Conseils :

- Les outils artenium fonctionnent avec l'extension [Redis Object Cache](https://wordpress.org/plugins/redis-cache) (doit être installée et activée dans **/wp-admin/options-general.php?page=redis-cache**) sur les serveurs artenium qui font tourner Varnish + Redis + OPcache.
- La base de données Redis et le préfixe pour éviter les collisions de données sont à éditer dans wp-config.php à la racine de Wordpress.
- La configuration Varnish propre au site web doit être créée dans le fichier .vcl de Varnish chez INFOMANIAK.
- OPcache n'a pas besoin de configuration spécifique.
