# Prompt de design : « Cyllos-Pay », interface moderne, animée et thémable

Ce document décrit précisément le style de la page de paiement Cyllos-Pay (`cyllos-pay/public/css/pay.css` et ses templates) pour le reproduire ailleurs. Le bloc **PROMPT** ci-dessous se copie tel quel dans un assistant IA ; la section « Référence » donne les valeurs exactes pour vérifier le résultat.

---

## PROMPT (à copier-coller)

````text
Tu es un designer-intégrateur front-end senior. Stylise l'écran / le composant que je te donne
avec le langage visuel « Cyllos-Pay » décrit ci-dessous : une carte flottante élégante, thémable
par variables CSS, avec des animations discrètes, fluides et premium. Respecte TOUTES les
contraintes techniques.

## 1. Esprit général
- Une seule carte centrée (largeur max 460 px) posée sur un fond ambiant vivant. Beaucoup d'air,
  une hiérarchie nette, des angles généreux, des ombres longues et douces.
- Moderne, calme, rassurant : on est sur un écran de confiance (paiement). Pas de gadgets :
  chaque animation a une fonction (guider l'œil, confirmer une action, signaler une attente).
- Tout est piloté par des variables CSS : changer 4 couleurs (accent, fond, carte, texte) doit
  suffire à re-thémer l'ensemble, en clair comme en sombre.

## 2. Design tokens (variables CSS sur :root)
--bg (fond de page)         clair #f1f4f9 | sombre #11141c
--fg (texte)                clair #1d2330 | sombre #e8ebf2
--card (surface de la carte) clair #ffffff | sombre #1c212c
--accent                    clair #2b6cb0 | sombre #63a4ff
--on-accent (texte sur accent) clair #ffffff | sombre #0b1220
--ok (succès)               clair #1a7f37 | sombre #6fdc8c
--err (erreur)              clair #b42318 | sombre #ff8a80
Dérivées (jamais de couleur en dur pour les bordures/textes secondaires) :
--border = color-mix(in srgb, var(--fg) 16%, transparent)
--muted  = color-mix(in srgb, var(--fg) 62%, transparent)
--soft   = color-mix(in srgb, var(--fg) 6%, transparent)
--ease   = cubic-bezier(.2, .8, .2, 1)   /* « out-expo » doux, utilisé partout */
--font   = system-ui, -apple-system, "Segoe UI", Roboto, sans-serif   (surchargeable : serif
           Georgia/Palatino, arrondie ui-rounded/Nunito, mono ui-monospace)
--r-card 20px | --r-ctl 12px | --r-box 14px   (surchargeables : « angles droits » 4/2/3 px,
           « très arrondis » 30/999/22 px)
`color-scheme: light dark` + media query prefers-color-scheme pour le mode sombre.
Typo : 16 px / 1.55 ; h1 1,35 rem, line-height 1,25, letter-spacing -0,01em ; petits textes
0,74 à 0,88 rem ; libellés de récap en capitales 0,76 rem, letter-spacing .05em, couleur --muted.

## 3. Mise en page
- body : min-height 100vh, display grid, place-items center, padding 20px 16px, overflow-x hidden.
- Carte : z-index 1, width 100 %, max-width 460 px, padding 32px 30px 24px (26px 20px 20px sous
  480 px), fond var(--card), bordure 1px var(--border), border-radius var(--r-card),
  ombre `0 24px 60px -24px color-mix(in srgb, #000 55%, transparent)`.
- Filet d'accent sur le bord haut de la carte (::before) : top -1px, left/right 28px, hauteur
  3px, arrondi bas 3px, `linear-gradient(90deg, transparent, var(--accent), transparent)`.
- En-tête de marque : logo (max 48 px de haut, 120 px de large, object-fit contain,
  border-radius 8px) + titre h1 sur une ligne flex gap 14px, marge basse 22px.
- Pied de carte « sécurisé » : icône cadenas SVG 14 px (stroke currentColor, 2) + texte
  0,76 rem --muted, centré, marge haute 22px.

## 4. Fond ambiant animé (derrière la carte, position fixed, pointer-events none)
Trois blobs ronds de la couleur --accent, `filter: blur(80px)`, taille 46vmax (le 3e : 30vmax) :
- blob 1 : en haut à gauche, opacité .22, animation drift-a 22s ease-in-out infinite alternate
- blob 2 : en bas à droite, opacité .16, drift-b 28s ease-in-out infinite alternate
- blob 3 : au centre-droit, opacité .10, drift-b 34s alternate-reverse
Variantes de fond : « formes flottantes » (blobs sans flou, border-radius 28 %, rotation lente
float-a/float-b, opacité .10-.12) et « uni » (fond masqué).
Keyframes : drift-a → translate(14vmax,10vmax) scale(1.15) ; drift-b → translate(-12vmax,-8vmax)
scale(1.2) ; float-a → translate(6vmax,8vmax) rotate(40deg) ; float-b → translate(-8vmax,-6vmax)
rotate(-30deg).

## 5. Animations d'entrée (cascade)
- Carte : `card-in` .6s var(--ease) both — de opacity 0, translateY(18px) scale(.98) à l'état normal.
- Chaque enfant direct de la carte : `fade-up` .55s var(--ease) both (opacity 0 + translateY(10px))
  avec un délai croissant par enfant : .06s, .11s, .16s, .21s, .26s, puis .31s pour les suivants.
- Logo : `logo-in` .7s var(--ease) .1s both (scale .8 + rotate -4deg → normal).
- Bloc récap : fade-up .5s var(--ease) .1s both.
- Puces de montants rapides : `chip-in` .45s var(--ease) both (translateY 8px, scale .94),
  délai +.05s par puce.

## 6. Composants

### Stepper (progression)
- Liste en grille (4 colonnes, ou 3 si une étape est masquée) : pastille ronde de 28px (bordure
  2px var(--border), fond var(--card), nombre en 0,8rem gras) au-dessus d'un libellé 0,74rem.
- Un trait de 2px relie les pastilles (::before positionné entre deux pastilles) ; il se
  « remplit » en var(--accent) avec `line-grow` .6s var(--ease) (scaleX 0 → 1, origin left)
  pour les étapes faites/en cours.
- Étape faite : pastille pleine --accent, texte --on-accent, icône coche SVG (stroke 3,5,
  arrondi), animation `pop` .4s (scale .6 → 1,18 → 1).
- Étape courante : bordure + chiffre en --accent, libellé en --fg gras 600, halo pulsant
  `ring` 2.4s ease-out infinite : box-shadow 0 0 0 0 (accent 45 %) → 0 0 0 9px transparent.
- Transitions : color .3s ; pastille all .3s var(--ease).

### Champs de formulaire
- Label 600, marge basse 6px. Input : width 100 %, padding 12px 14px, font inherit, fond
  var(--soft), bordure 1px var(--border), border-radius var(--r-ctl).
- Hover : bordure = color-mix(accent 45 %, border). Focus : bordure --accent, fond transparent,
  anneau `0 0 0 4px color-mix(in srgb, var(--accent) 22%, transparent)` ; transitions .2s.
- Aide : <small> 0,82rem --muted. Erreurs : --err, 0,88rem, liste sans puces.

### Boutons
- Principal : pleine largeur, padding 13px 16px, gras 700, couleur --on-accent, fond
  `linear-gradient(135deg, var(--accent), color-mix(in srgb, var(--accent) 72%, #fff))`,
  border-radius var(--r-ctl), ombre colorée `0 8px 20px -8px color-mix(accent 80 %, transparent)`.
- Reflet qui balaie au survol (::after) : dégradé 110° transparent → blanc 28 % → transparent,
  translateX(-120 %) → (120 %) en .6s var(--ease).
- Hover : translateY(-2px) + ombre plus grande. Active : translateY(0) scale(.985)
  brightness(.95). Transitions .18s var(--ease).
- Secondaire (.secondary) : transparent, bordure 1px --border, couleur --fg, sans ombre ni reflet ;
  hover : bordure --accent + fond --soft.
- Puce de montant (.chip) : bouton compact flex 1 1 64px, fond --soft, bordure --border, gras 600 ;
  hover : bordure --accent, fond accent 14 %.
- focus-visible partout : outline 2px --accent, offset 3px.

### Messages
- Alerte : padding 10px 14px, radius var(--r-ctl), fond color-mix(err 10 %), bordure
  color-mix(err 35 %), texte --err.
- Notice : même forme avec fond --soft et bordure --border.
- Succès : texte --ok, gras 600.
- Attente : notice flex avec spinner 18px (bordure 2,5px --border, partie haute --accent,
  rotation `spin` .8s linear infinite).

### Récap (liste de définition)
- Boîte : padding 14px 16px, fond --soft, bordure --border, radius var(--r-box). dt en petites
  capitales --muted, dd marge basse 12px (0 pour le dernier), overflow-wrap anywhere.

### Écran de réussite
- Coche SVG 72px dessinée en deux temps : le cercle (stroke --ok, 3px, dasharray 151) se trace
  en .7s var(--ease) .1s ; la coche (stroke 4px, round, dasharray 40) se trace en .45s à .7s.
  Keyframe `draw` : stroke-dashoffset → 0.
- Halo `halo` 2.4s derrière la coche : radial-gradient var(--ok) 28 % → transparent, de scale .4
  (opacité 0) à 1,6 (opacité 0).
- Lien secondaire en --accent, sans soulignement, qui glisse de 2px à droite au survol.

## 7. Personnalisation par client (thème dynamique)
- Les 4 couleurs d'un client (accent, fond, carte, texte) sont injectées via une feuille
  `theme.css` qui redéfinit les variables sur :root. Les valeurs manquantes sont DÉDUITES pour
  rester lisibles : sombre si luminance du fond < 0,4 ; texte #f8fafc (sombre) ou #0f172a
  (clair) ; fond manquant = carte mélangée au noir ; carte manquante = fond éclairci de 7 %
  (sombre) ou #fff (clair) ; --on-accent = #0f172a si luminance(accent) > 0,55 sinon #fff ;
  --ok/--err ajustés au mode.
- Les options de style (police, rayon, fond) sont des attributs data-* sur <body>
  (`data-font`, `data-radius`, `data-bg`) qui redéfinissent --font, --r-* ou le fond. Aucune
  valeur libre venant de l'utilisateur : tout est en liste blanche.

## 8. Accessibilité et performances
- `@media (prefers-reduced-motion: reduce)` : `*, *::before, *::after { animation-duration:
  .01ms !important; animation-delay: 0s !important; animation-iteration-count: 1 !important;
  transition-duration: .01ms !important; }`.
- Contrastes calculés (jamais de texte clair sur accent clair), focus visible, labels associés,
  zones tactiles >= 44 px, aria-current sur l'étape courante, textes alternatifs masqués
  (.sr) pour les icônes de coche.
- Animations uniquement sur transform / opacity / box-shadow / stroke-dashoffset (GPU, pas de
  reflow). Responsive : sous 480 px, padding réduit et libellés du stepper à 0,7rem.

## 9. Contraintes techniques OBLIGATOIRES
- CSS pur : AUCUN JavaScript pour le style ni les animations, AUCUN style inline, AUCUNE police
  web ni CDN (pile système uniquement). La page tourne sous une CSP stricte
  (`default-src 'none'; style-src 'self'`), donc : une feuille .css locale, des SVG inline, des
  attributs data-* pour les variantes.
- Utilise color-mix(), les variables CSS et les clamp/vmax ; pas de framework, pas de préprocesseur.
- Garde les noms de classes simples, en BEM léger (.card, .brand__logo, .steps, .recap, .chip…).

## 10. Livrables attendus
1. Le fichier CSS complet, commenté par sections (tokens, fond, carte, stepper, champs,
   boutons, messages, récap, réussite, variantes, reduced-motion).
2. Le HTML/Twig de l'écran restylé, sémantique et accessible.
3. Une courte liste des variables à changer pour re-thémer l'écran.
4. Vérifie mentalement : mode clair ET sombre, mobile 375 px, reduced-motion, contrastes AA.

Écran / composant à styliser : [DÉCRIS ICI TON ÉCRAN, SES CHAMPS ET SES ACTIONS]
Couleurs de la marque : accent [#......], fond [#......] (optionnel), carte [#......] (optionnel),
texte [#......] (optionnel)
````

---

## Référence : où se trouve chaque chose

| Sujet | Fichier |
|---|---|
| Tous les styles et animations | `cyllos-pay/public/css/pay.css` |
| Squelette (fond, carte, en-tête, pied) | `cyllos-pay/templates/base.html.twig` |
| Stepper | `cyllos-pay/templates/pay/_steps.html.twig` |
| Écrans du parcours | `cyllos-pay/templates/pay/{email,confirm,amount,summary,return}.html.twig` |
| Thème dynamique, déduction des couleurs | `cyllos-pay/src/Pay/PayClient.php` (`themeCss()`) |
| Variantes police / coins / fond | `data-font`, `data-radius`, `data-bg` sur `<body>`, règles en bas de `pay.css` |
| Sélecteur de couleurs côté back-office | `cyllos/templates/admin/client/_pay_colors.html.twig`, `assets/controllers/pay_palette_controller.js` |

## Adapter ce style à une autre application

- **Back-office / application avec JavaScript autorisé** : on peut ajouter des micro-interactions
  (compteurs animés, transitions de page, toasts) en gardant les mêmes durées (.18 à .6 s),
  la même courbe `--ease` et les mêmes déclencheurs (entrée en cascade, survol, focus).
- **Autre palette** : ne changer que `--accent`, `--bg`, `--card`, `--fg` (+ `--on-accent`).
- **Autre densité** : réduire `--r-card/--r-ctl/--r-box` et les paddings, garder les animations.
