Informations :

- Les outils artenium fonctionnent avec l'extension [Redis Object Cache](https://wordpress.org/plugins/redis-cache) (doit être installée et activée dans `/wp-admin/options-general.php?page=redis-cache`) sur les serveurs artenium qui font tourner Varnish + Redis + OPcache.
- La base de données Redis et le préfixe pour éviter les collisions de données sont à éditer dans wp-config.php à la racine de Wordpress :
  ```
  // REDIS CACHE
  define( 'WP_REDIS_DATABASE', 2 );
  define( 'WP_REDIS_PREFIX', 'prefixedusite' );
  ```
- La configuration Varnish propre au site web doit être créée dans le fichier .vcl de Varnish chez INFOMANIAK.
- OPcache n'a pas besoin de configuration spécifique.

Release :

- Lors de la création d'une release, penser à bien mettre à jour le numéro de version dans `artenium-wp-tools.php` vers une version supérieure (ex: 1.0.2 > 1.0.3) mais aussi dans le tag de la release.
- Une release doit être accompagnée du zip téléchargé à la racine du Github (avec le bouton sur l'image ci-dessous) dans laquelle le répertoire de l'extension `artenium-wp-tools-main` a été renommé `artenium-wp-tools` sinon les mises à jour automatiques ne marcheront pas.
<img width="399" height="330" alt="image" src="https://github.com/user-attachments/assets/93fab1af-97ab-4dc6-a3e1-b757e5589a6e" />
