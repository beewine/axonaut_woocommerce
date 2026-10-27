# Axonaut pour WooCommerce (édition communautaire)

Fork du module Axonaut ↔ WooCommerce, dont le développement a été arrêté par Axonaut. Les anciens
modules restent utilisables mais ne reçoivent plus de nouveautés : ce dépôt les fait évoluer.

Le code d'origine (GPL-2.0+) est celui du module Axonaut 2.4.2.

## Ce que fait le module

| Domaine | Comportement |
|---|---|
| Produits | Rapprochement par référence (UGS = référence Axonaut), puis par ID WooCommerce. Synchronisation en arrière-plan. |
| Stock | WooCommerce vers Axonaut (valeur absolue, 30 s après le changement), Axonaut vers WooCommerce (webhook `stockLog.created`), ou les deux. |
| Titre, descriptif, prix HT | Sens réglable champ par champ. Les modifications d'Axonaut sont reprises toutes les heures. |
| Commandes | « En cours » : facture ; « Terminée » : paiement. Une seule facture par commande, nouvelles tentatives en cas d'échec. |
| Avoirs | Un remboursement WooCommerce crée un avoir lié à la facture ; une commande annulée après facturation crée un avoir pour le solde. |
| Arrondis | Écart de total signalé en note de commande, ou ligne d'arrondi (jusqu'à 5 centimes, expérimental). |

## Réglages (menu Axonaut, onglet Configuration)

- **Rapprochement des produits** : référence puis ID (défaut) ou ID seul (comportement historique).
- **Descriptif envoyé** : description courte (défaut), longue, ou courte à défaut longue.
- **Sens de synchronisation** pour le stock, le titre, le descriptif et le prix : aucun, WooCommerce vers Axonaut,
  Axonaut vers WooCommerce, ou dans les deux sens. Sans réglage enregistré, les anciens réglages
  (« Synchronisation des produits » et des stocks) fixent les valeurs par défaut.
- **Si les deux côtés ont changé** : WooCommerce ou Axonaut l'emporte. Une comparaison à trois voies
  (dernière valeur synchronisée, mémorisée sur le produit) évite d'écraser une modification récente.
- **Avoirs** et **Arrondis** : voir ci-dessus.

## Webhook de stock (Axonaut vers WooCommerce)

Dans Axonaut (API, Webhooks, « Add an app ») :

1. URL de rappel : celle affichée dans le module.
2. En-tête `X-Axonaut-Secret` : le secret affiché dans le module. (La méthode historique, une clé API
   WooCommerce en `Authorization: Basic …`, reste acceptée.)
3. En-tête `Accept: application/json`, topic `stockLog.created`.

Sans secret ni clé valide, la route répond 401.

## Utiliser les champs de l'API Axonaut

Seuls les champs documentés dans l'API publique v2 sont utilisés (produits, factures, paiements, stock),
à l'exception de trois points d'entrée privés hérités du module d'origine : création en masse des clients,
création d'un client avec son contact, suppression des paiements d'une commande.

## À valider sur un compte Axonaut réel

Ce développement a été testé sans WordPress ni compte Axonaut (`php tests/run.php`, stubs). À vérifier avant
une mise en production :

1. Quand le produit existe déjà dans Axonaut, la facture reprend-elle le nom et le descriptif de la ligne
   ou ceux de la fiche produit ? (la documentation ne le précise pas)
2. Le sens des champs `total` et `tax_amount` d'une facture, et la méthode de calcul des arrondis
   d'Axonaut (le modèle de la ligne d'arrondi est une hypothèse).
3. Si Axonaut décrémente son stock à la création de la facture : le stock envoyé à retardement évite le double
   décompte, à confirmer en mode « dans les deux sens ».
4. Limite de débit de l'API : la file d'attente espace les produits de 2 s et réessaie après un 429.

## Changements de comportement par rapport à 2.4.2

- La route du webhook de stock exige un secret ou une clé WooCommerce (elle était ouverte à tous).
- Les mises à jour de catalogue passent par l'API documentée et une file d'attente, plus par un endpoint privé en un bloc.
- Le journal est dans `wp-content/uploads/axonaut-logs` (il ne s'écrivait plus : le message était vidé avant l'écriture).
- La synchronisation et le téléchargement du journal exigent d'être administrateur.
- Le contrôle de mise à jour qui renvoyait vers le site d'Axonaut est retiré.

## Développement

```bash
php tests/run.php          # tests sans WordPress
for f in $(git ls-files '*.php'); do php -l "$f"; done
```
