Conseils :
- Fonctionnent avec l'extension [Redis Object Cache](https://wordpress.org/plugins/redis-cache) sur les serveurs artenium qui font tourner Varnish + Redis + OPCache.
- La base de données Redis et le préfixe pour éviter les collisions de données sont à éditer dans wp-config.php à la racine de Wordpress.
- L'accès au monitoring Redis se fait via **/wp-admin/options-general.php?page=redis-cache**.
