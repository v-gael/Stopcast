import './stimulus_bootstrap.js';

// En dev, la barre de debug arrive avec un script qui la charge. Après une visite Turbo, ce script ne
// tourne pas : le morphing n'exécute pas les scripts en ligne, et un remplacement de page les exécute
// sous la CSP de la première page, qui refuse le nonce de la nouvelle. On charge donc la barre après
// chaque rendu Turbo si elle attend encore (son bouton « Cancel » n'existe que pendant l'attente),
// pas si elle vient d'un instantané du cache, déjà chargé. Sans barre (prod), rien ne se passe.
document.addEventListener('turbo:render', () => {
    const toolbar = document.querySelector('[id^="sfwdt"]');
    const token = toolbar?.id.slice('sfwdt'.length);

    if (token && window.Sfjs && document.getElementById(`sfLoadCancel-${token}`)) {
        window.Sfjs.loadToolbar(token);
    }
});
