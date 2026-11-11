FROM nginx:alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY src/ /usr/share/nginx/html/
# Fingerprint asset URLs (?v=<hash>) so nginx may cache them for a year — see stamp-assets.sh.
COPY stamp-assets.sh /tmp/stamp-assets.sh
RUN sh /tmp/stamp-assets.sh /usr/share/nginx/html && rm /tmp/stamp-assets.sh
