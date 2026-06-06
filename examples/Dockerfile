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

# Register and install. The optional `composer_auth` buildkit secret carries
# COMPOSER_AUTH JSON (e.g. {"github-oauth":{"github.com":"<token>"}}) so
# transitive metadata fetches don't hit anonymous GitHub rate limits.
# Its value is stripped of stray CR/LF before use: docker/build-push-action's
# `secrets:` block can introduce a newline that Composer otherwise rejects as an
# "invalid character" in the github-oauth token.
WORKDIR /var/www/html
RUN --mount=type=secret,id=composer_auth,required=false \
    if [ -f /run/secrets/composer_auth ]; then export COMPOSER_AUTH="$(tr -d '\r\n' < /run/secrets/composer_auth)"; fi; \
    composer config repositories.snipeit-oidc path packages/snipeit-oidc \
 && composer require 'bausteln/snipeit-oidc:*@dev' --no-interaction --no-scripts \
 && composer dump-autoload --optimize

# Patch the login template. Fails the build (rather than producing a broken
# image) if the anchor isn't found — re-verify after any Snipe-IT upgrade.
RUN sed -i '0,/<\/form>/{s|</form>|</form>\n        @include('"'"'oidc::login-button'"'"')|}' \
        resources/views/auth/login.blade.php \
 && grep -q "oidc::login-button" resources/views/auth/login.blade.php \
        || (echo "ERROR: login.blade.php patch did not apply — check Snipe-IT version" && exit 1)

RUN chown -R www-data:www-data packages/snipeit-oidc
