# VersarAI site update: deploy notes

Upload everything in this folder over your site root. Files you did NOT receive back and should leave as they are:
`playlist-feed.php`, `favicon.ico` and the favicon PNGs, `img/versarai_logo.png`, `img/aria-demo.png`,
`img/one-person-many-sims.png`, and `videos/*.mp4`.

## Do these on the server, in this order
1. `scripts/make-posters.sh`        creates the four video posters (needs ffmpeg and `videos/*.mp4`)  [1.1]
2. `scripts/optimize-images.sh`     creates `img/aria-demo.webp` (2276px) and a lighter PNG fallback  [2.4]
   Do this BEFORE going live: index.html already points at aria-demo.webp.
3. Set the CRM webhook: environment variable `VERSARAI_CRM_WEBHOOK` (used by contact.php)               [2.1]
4. Replace the booking link: search `BOOKING_URL` (currently a mailto) and set your scheduler URL       [2.1]
5. WAF / CDN: make sure it passes the origin's real status codes (404 must stay 404) and does not
   turn missing paths into 500. `.htaccess` handles the origin side.                                    [2.2]
6. Submit `sitemap.xml` in Search Console.

## Please confirm before publishing (marked with CONFIRM comments in the HTML)
- docs.html: tool and field names are a draft built from the public description of SimCore.
- partners.html: the neutrality commitments must be ones the company will keep.
- research.html: metric definitions follow the paper's evaluation section; check against the latest arXiv version.
