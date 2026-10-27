# Protocole de test sur un compte Axonaut réel

Ce protocole lève les quatre points de la section « À valider sur un compte Axonaut réel » du README.
Il se déroule sur un WordPress de test (pas la boutique de production), branché sur un compte Axonaut
de test. Durée estimée : une demi-journée, dont une heure d'attente pour la file d'attente et les délais.

## 0. Avant de commencer

### Précautions

- Utiliser un compte Axonaut dédié aux essais, ou à défaut une entreprise de test. Axonaut ne propose pas
  de bac à sable : tout ce qui est créé l'est pour de vrai, et une facture validée ne se supprime pas
  toujours facilement. Préfixer tous les noms de test par `ZZTEST` pour les retrouver et les archiver.
- Ne pas laisser le module envoyer de vrais clients : utiliser des commandes de test passées avec une adresse
  e-mail de test.
- Sauvegarder la base WordPress avant le premier essai.
- Les clés API restent hors du dépôt. Dans les commandes ci-dessous, la clé Axonaut est lue dans la
  variable `AXONAUT_KEY` de votre shell.

### Installation

1. WordPress de test avec WooCommerce, module installé depuis la branche `feat/credit-notes-and-rounding`.
2. Réglages du module : clé API Axonaut, « Rapprochement des produits » = UGS puis ID, description = courte
   puis longue, avoirs activés, arrondis = `off` (on le change au test 3).
3. Webhook de stock créé dans Axonaut avec l'URL et le secret affichés par le module (README, section
   « Webhook de stock »).
4. Le journal du module (`wp-content/uploads/axonaut-logs`) téléchargeable depuis l'écran du module : le
   garder ouvert pendant les essais, c'est la preuve de chaque test.

### Jeu de données

Créer dans WooCommerce, avec le préfixe `ZZTEST` :

| Réf. | Produit | UGS | Prix HT | TVA | Stock | Description courte | Description longue |
|---|---|---|---|---|---|---|---|
| A | ZZTEST Simple | ZZ-A | 10,00 | 20 % | 10 | Court A | Long A |
| B | ZZTEST Centimes | ZZ-B | 3,33 | 20 % | 10 | Court B | Long B |
| C | ZZTEST TVA réduite | ZZ-C | 7,99 | 5,5 % | 10 | Court C | Long C |
| D | ZZTEST Déjà dans Axonaut | ZZ-D | 20,00 | 20 % | 10 | Court D | Long D |

Dans Axonaut, créer à la main un produit « ZZTEST Fiche Axonaut D » avec la référence `ZZ-D`, un descriptif
« Descriptif fiche Axonaut D » et un prix de 20,00 : il servira au test 1.

### Lire ce qu'Axonaut a reçu

Deux moyens, à croiser :

- L'interface Axonaut (facture, ligne par ligne).
- L'API, pour avoir les champs bruts :

```bash
# Facture retrouvée par le numéro de commande envoyé (champ internal_ref côté Axonaut)
curl -s -H "userApiKey: $AXONAUT_KEY" "https://axonaut.com/api/v2/invoices?internal_ref=NUMERO_COMMANDE" | jq .

# Produit retrouvé par sa référence
curl -s -H "userApiKey: $AXONAUT_KEY" "https://axonaut.com/api/v2/products?product_code=ZZ-D" | jq .
```

Enregistrer chaque réponse JSON dans un fichier daté : elles serviront à trancher les points 1 et 2.

---

## Test 1. Nom et descriptif de la ligne : ligne de commande ou fiche produit ?

Question : quand le produit existe déjà dans Axonaut, la facture affiche-t-elle le nom et le descriptif
envoyés avec la ligne, ou ceux de la fiche produit ?

Le module envoie, pour un produit reconnu, `id` + `name` + `description` + `price`. On teste les deux cas.

| Étape | Action | À relever |
|---|---|---|
| 1.1 | Lancer la synchronisation complète. Vérifier que A, B, C, D existent dans Axonaut, et que D n'a pas été dupliqué (la fiche manuelle `ZZ-D` doit avoir été reliée). | Nombre de produits ZZTEST dans Axonaut. Attendu : 4. |
| 1.2 | Passer une commande WooCommerce avec le produit **A** (créé par le module). | Nom et descriptif de la ligne sur la facture. |
| 1.3 | Passer une commande avec le produit **D** (fiche Axonaut préexistante, titre WooCommerce différent). | Nom et descriptif de la ligne : ceux de WooCommerce (« ZZTEST Déjà dans Axonaut ») ou ceux de la fiche (« ZZTEST Fiche Axonaut D ») ? |
| 1.4 | Modifier dans WooCommerce le titre de A, **sans** resynchroniser (sens du titre réglé sur « aucun »), repasser une commande. | Le nom sur la facture suit-il WooCommerce ou la fiche Axonaut ? |
| 1.5 | Relire la fiche produit D dans Axonaut après la facture. | La facture a-t-elle modifié la fiche ? |

Décision :

- La ligne de facture prime : rien à changer, c'est ce que le module suppose.
- La fiche produit prime : le titre et le descriptif de la facture viennent d'Axonaut. Il faut alors régler
  les sens du titre et du descriptif sur « dans les deux sens » ou « depuis Axonaut », et corriger le
  texte de réponse à Xavier (le titre de la facture n'est plus celui de WooCommerce).

---

## Test 2. Sens de `total` et `tax_amount`

Question : sur la facture créée, `total` est-il le HT ou le TTC, et `tax_amount` la TVA seule ?

Le module compare le total WooCommerce à `total` ou à `total + tax_amount` (accepte les deux lectures).
Il faut savoir laquelle est la bonne pour ne pas lever de fausses alertes.

1. Passer une commande avec 2 × A à 10,00 HT, TVA 20 % (attendu : 20,00 HT, 4,00 TVA, 24,00 TTC).
2. Récupérer la facture par l'API et relever `total`, `tax_amount`, et tout autre champ de montant.
3. Remplir :

| Champ API | Valeur | Interprétation |
|---|---|---|
| `total` | | HT (20,00) ou TTC (24,00) ? |
| `tax_amount` | | TVA seule (4,00) ? |

4. Comparer avec l'interface Axonaut (HT, TVA, TTC affichés).

Décision : si `total` est le TTC et `tax_amount` la TVA, la comparaison `total + tax_amount` est fausse et
il faut ne garder que `total`. Si `total` est le HT, ne garder que `total + tax_amount`. Ajuster
`Axonaut_Rounding::matches_invoice()` en conséquence (une ligne) et ses tests.

---

## Test 3. Arrondis

Question : quand le total d'Axonaut diffère de celui de WooCommerce de quelques centimes, la ligne
d'arrondi corrige-t-elle l'écart sans créer d'incohérence comptable ?

### 3a. Constater les écarts (arrondis sur `off`)

Passer ces commandes, sans ligne d'ajustement, et relever le total TTC WooCommerce et le total TTC Axonaut.

| Commande | Contenu | Total WooCommerce attendu |
|---|---|---|
| 3.1 | 3 × B (3,33 HT, TVA 20 %) | 11,99 HT + 2,40 TVA |
| 3.2 | 7 × C (7,99 HT, TVA 5,5 %) | 55,93 HT + 3,08 TVA |
| 3.3 | 1 × B + 1 × C + 1 × A (deux taux) | à relever dans WooCommerce |
| 3.4 | Commande avec un coupon de 10 % | à relever dans WooCommerce |
| 3.5 | Commande avec frais de port à 4,95 TTC | à relever dans WooCommerce |

Pour chaque commande, noter l'écart en centimes et le sens de l'arrondi d'Axonaut : par ligne, par taux de
TVA, ou sur le total. Cette observation valide ou invalide le modèle du module (HT arrondi par ligne, TVA
arrondie par taux).

### 3b. Essayer l'ajustement (arrondis sur `adjust`)

Passer le réglage sur « ajuster » puis refaire 3.1 à 3.5 (nouvelles commandes).

| Vérification | Attendu |
|---|---|
| Le total Axonaut égale le total WooCommerce | oui, écart nul |
| Une ligne « Ajustement d'arrondi » apparaît, montant ≤ 0,05 | oui, uniquement quand il y a un écart |
| La TVA de la ligne d'ajustement est cohérente (déclaration de TVA) | taux utilisé = celui du groupe concerné |
| Aucune ligne d'ajustement quand il n'y a pas d'écart (commande 1 × A) | oui |

Point à faire valider par le comptable : la présence d'une ligne d'arrondi sur les factures de vente est
acceptable en comptabilité.

Décision : si l'écart est nul avec `off` sur tous les cas, laisser `off` par défaut. Si des écarts
apparaissent et que la ligne d'ajustement les corrige proprement, documenter `adjust` comme recommandé. Si
l'ajustement crée une erreur de TVA, le laisser en simple signalement (note de commande) et ne pas l'activer.

---

## Test 4. Stock : double décompte en mode « dans les deux sens »

Question : Axonaut décrémente-t-il son stock à la création d'une facture ? Si oui, le stock poussé par le
module (valeur absolue, après 30 s) suffit-il à éviter un double décompte ?

Régler le sens du stock sur « dans les deux sens ». Produit utilisé : A, stock initial 10 des deux côtés.

| Étape | Action | À relever |
|---|---|---|
| 4.1 | Relever le stock de A dans WooCommerce et dans Axonaut (fiche produit). | 10 / 10 attendu. |
| 4.2 | Passer une commande de 2 × A. Noter l'heure. | Stock WooCommerce : 8 immédiatement. |
| 4.3 | Juste après la création de la facture (avant 30 s), relever le stock Axonaut. | Il a baissé tout seul (8) ou non (10) ? C'est la réponse à la question. |
| 4.4 | Attendre 60 s. Relever les deux stocks. | 8 / 8 attendu. Pas 6. |
| 4.5 | Dans le journal, relever les lignes de stock : un envoi de `8` en valeur absolue, et éventuellement un webhook `stockLog.created` reçu en retour. | Pas de boucle : après le webhook, aucun nouvel envoi. |
| 4.6 | Dans Axonaut, ajuster le stock de A à 5 à la main (mouvement de stock). | Le webhook met WooCommerce à 5 (secret valide). |
| 4.7 | Refaire 4.2 avec le secret du webhook volontairement faux (en-tête modifié dans Axonaut). | Le webhook est refusé (401 dans le journal), le stock WooCommerce ne change pas. Rétablir ensuite le bon secret. |
| 4.8 | Annuler la commande de l'étape 4.2 dans WooCommerce (le stock est remis à 7 dans WooCommerce), attendre 60 s. | Axonaut suit (valeur absolue) et aucun double retour. |

Décision :

- Axonaut ne touche pas son stock à la facture (4.3 = 10) : le module est correct tel quel.
- Axonaut décrémente aussi (4.3 = 8) et la valeur finale est 8 : le délai de 30 s et l'envoi absolu suffisent.
- Valeur finale 6 : le module doit désactiver l'envoi du stock après une commande pour le mode « dans les
  deux sens », ou comparer le stock Axonaut avant d'envoyer. À corriger avant la mise en production.

---

## Test 5. Limite de débit de l'API

Question : à quelle cadence Axonaut renvoie-t-il un code 429 ? La file d'attente (un produit toutes les
2 secondes) suffit-elle ?

1. Créer 60 produits de test (import CSV WooCommerce, préfixe `ZZTEST-LOT`, UGS `ZZL-001` à `ZZL-060`).
2. Lancer la synchronisation complète. Chronométrer du premier au dernier produit visible dans Axonaut.
3. Dans le journal, compter les lignes `HTTP 429` et le temps d'attente mentionné.
4. Pendant la synchronisation, passer 3 commandes : elles ne doivent pas être retardées ni perdues.
5. Si aucun 429 : relancer avec 300 produits pour s'approcher d'une limite réelle.

| Mesure | Valeur |
|---|---|
| Durée de synchro de 60 produits | |
| Nombre de réponses 429 | |
| Facture créée pendant la synchro : délai | |

Décision : aucun 429 à 2 s par produit, garder. Des 429 fréquents : passer l'intervalle à 3 ou 4 secondes
(constante dans `Axonaut_Queue`) et exposer l'intervalle dans les réglages.

---

## Test 6. Contrôles complémentaires (rapides)

Ces cas ont été testés avec une API simulée seulement.

| Test | Action | Attendu |
|---|---|---|
| 6.1 Rapprochement par UGS | Produit WooCommerce avec l'UGS d'une fiche Axonaut existante, sans ID. | Lien automatique, pas de doublon. |
| 6.2 Produit sans UGS | Produit sans UGS commandé. | Rapprochement par ID WooCommerce, facture correcte. |
| 6.3 Avoir partiel | Rembourser 1 × A sur une commande payée. | Avoir lié à la facture d'origine, quantité négative, total cohérent. |
| 6.4 Commande annulée | Annuler une commande déjà facturée. | Avoir total (si activé) et paiements supprimés. |
| 6.5 Idempotence | Rejouer l'envoi d'une commande déjà facturée depuis l'écran du module. | Pas de seconde facture. |
| 6.6 Erreur et reprise | Couper le réseau du WordPress, passer une commande, rétablir. | Nouvelle tentative automatique (60 s, 300 s, 1800 s), note de commande après l'échec final. |
| 6.7 Client existant | Commande d'un client déjà présent dans Axonaut (même e-mail). | Pas de doublon de client. |
| 6.8 Import périodique | Modifier le prix d'un produit dans Axonaut (sens du prix « depuis Axonaut »). | WooCommerce reçoit le prix à l'import horaire, converti en HT ou TTC selon le réglage de la boutique. |

---

## Compte rendu

Reporter les résultats dans le tableau ci-dessous, puis remonter les écarts (une issue par écart) :

| Test | Résultat observé | Conforme au module ? | Action |
|---|---|---|---|
| 1 Nom et descriptif | | | |
| 2 `total` / `tax_amount` | | | |
| 3 Arrondis | | | |
| 4 Stock bidirectionnel | | | |
| 5 Limite de débit | | | |
| 6 Contrôles complémentaires | | | |

Quand tous les tests sont conformes, retirer la section « À valider » du README (ou la remplacer par la
date et le résultat de la validation).

## Nettoyage

- Dans Axonaut : archiver les produits `ZZTEST*` et `ZZL-*`, les clients de test, et supprimer ou annuler
  les factures de test (par avoir si elles sont verrouillées).
- Dans WordPress : supprimer les produits et commandes de test, régénérer le secret du webhook, retirer
  l'URL de rappel du webhook dans Axonaut si l'environnement de test disparaît.
- Révoquer la clé API Axonaut de test si elle a été créée pour l'occasion.
