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

# Register and install. The optional `github_token` buildkit secret carries a
# raw GitHub token so transitive metadata fetches don't hit anonymous GitHub
# rate limits. We whitelist it to valid token characters (tr -cd) before
# configuring composer: docker/build-push-action's `secrets:` block can inject a
# stray character that the base image's Composer rejects as an "invalid
# character" in the github-oauth token when it loads config. (The token *format*
# is fine — Composer accepts ghs_/ghp_ tokens; only the injected byte breaks it.)
WORKDIR /var/www/html
RUN --mount=type=secret,id=github_token,required=false \
    if [ -f /run/secrets/github_token ]; then \
      composer config --global github-oauth.github.com "$(tr -cd 'A-Za-z0-9_' < /run/secrets/github_token)"; \
    fi; \
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
