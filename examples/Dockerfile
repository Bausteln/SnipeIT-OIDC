# Example: custom Snipe-IT image with snipeit-oidc baked in.
#
# Intended usage:
#
#   mkdir my-snipeit-build && cd my-snipeit-build
#   git clone git@github.com:Bausteln/SnipeIT-OIDC.git snipeit-oidc
#   cp snipeit-oidc/examples/Dockerfile .
#   docker build --build-arg SNIPEIT_VERSION=v8.5.0 \
#                -t registry.example.com/snipeit-oidc:v8.5.0-oidc1 .
#
# The build expects `snipeit-oidc/` to sit next to the Dockerfile.

ARG SNIPEIT_VERSION=v8.5.0
FROM snipe/snipe-it:${SNIPEIT_VERSION}

# Vendor the plugin
COPY snipeit-oidc /var/www/html/packages/snipeit-oidc

# Register and install the plugin into Snipe-IT. The base image is a
# `composer install --no-dev` production build, so we update WITHOUT dev
# dependencies here too (--update-no-dev): pulling Snipe-IT's dev tools
# (phpunit, mockery, …) is wrong for a prod image and would need authenticated
# cross-repo GitHub archive downloads that the Actions GITHUB_TOKEN cannot do
# (it 401s). The plugin's runtime dep (jumbojett/openid-connect-php) resolves
# anonymously from Packagist — a handful of requests, well under the rate limit.
WORKDIR /var/www/html
RUN composer config repositories.snipeit-oidc path packages/snipeit-oidc \
 && composer require 'bausteln/snipeit-oidc:*@dev' --no-interaction --no-scripts --update-no-dev \
 && composer dump-autoload --optimize

# Patch the login template. Fails the build (rather than producing a broken
# image) if the anchor isn't found — re-verify after any Snipe-IT upgrade.
RUN sed -i '0,/<\/form>/{s|</form>|</form>\n        @include('"'"'oidc::login-button'"'"')|}' \
        resources/views/auth/login.blade.php \
 && grep -q "oidc::login-button" resources/views/auth/login.blade.php \
        || (echo "ERROR: login.blade.php patch did not apply — check Snipe-IT version" && exit 1)

RUN chown -R www-data:www-data packages/snipeit-oidc
