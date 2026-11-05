<?php

declare(strict_types=1);

namespace App\Logging;

/**
 * Masque les adresses IP et les emails d'un texte : IPv4 tronquées en /24,
 * IPv6 en /48, emails réduits à leurs premières lettres et à leur domaine de.
 *
 * premier niveau (j***@e***.com). Le jeton d'un lien de réinitialisation du mot
 * de passe, secret tant qu'il est valable, est remplacé.
 */
final class PersonalDataMasker
{
    private const string RESET_TOKEN = '#(/reset-password/reset/)[^/?\s"\'&]+#';

    private const string EMAIL = '/(?<![\w.%+-])([A-Za-z0-9])[\w.%+-]*@([A-Za-z0-9])[A-Za-z0-9.-]*\.([A-Za-z]{2,})(?![\w-])/';

    private const string OCTET = '(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)';

    private const string IPV4 = '/(?<![\w.])((?:'.self::OCTET.'\.){3})'.self::OCTET.'(?!\w|\.\d)/';

    // Candidats seulement (au moins deux « : ») : filter_var() décide. Les
    // bornes excluent les « :: » de PHP (Foo::bar), collés à des lettres.
    private const string IPV6_CANDIDATE = '/(?<![\w:.])[0-9A-Fa-f:]*:[0-9A-Fa-f:]*:[0-9A-Fa-f:]*(?:\d{1,3}(?:\.\d{1,3}){3})?(?![\w:]|\.\d)/';

    public function mask(string $text): string
    {
        $text = preg_replace(self::RESET_TOKEN, '${1}***', $text) ?? $text;
        $text = preg_replace(self::EMAIL, '${1}***@${2}***.${3}', $text) ?? $text;
        $text = preg_replace_callback(self::IPV6_CANDIDATE, $this->maskIpv6(...), $text) ?? $text;

        return preg_replace(self::IPV4, '${1}0', $text) ?? $text;
    }

    /**
     * Masque les chaînes d'un tableau, à toute profondeur ; les autres valeurs
     * (objets compris) restent telles quelles.
     *
     * @template TKey of array-key
     *
     * @param array<TKey, mixed> $values
     *
     * @return array<TKey, mixed>
     */
    public function maskValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (\is_string($value)) {
                $values[$key] = $this->mask($value);
            } elseif (\is_array($value)) {
                $values[$key] = $this->maskValues($value);
            }
        }

        return $values;
    }

    /**
     * @param array<string> $match
     */
    private function maskIpv6(array $match): string
    {
        $address = $match[0];
        $binary = false === filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) ? false : inet_pton($address);
        if (false === $binary) {
            return $address;
        }

        // IPv4 dans IPv6 (::ffff:a.b.c.d) : la partie IPv4 suit la règle IPv4.
        if (str_starts_with($binary, str_repeat("\0", 10)."\xff\xff")) {
            return '::ffff:'.implode('.', [\ord($binary[12]), \ord($binary[13]), \ord($binary[14]), 0]);
        }

        return (string) inet_ntop(substr($binary, 0, 6).str_repeat("\0", 10));
    }
}
