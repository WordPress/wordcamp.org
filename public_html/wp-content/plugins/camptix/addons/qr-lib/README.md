# Bundled QR rendering library

`vendor/` here is a vendored, **generation-only** build of
[chillerlan/php-qrcode](https://github.com/chillerlan/php-qrcode), used by the CampTix QR Check-In
addon to render attendee QR images (see `../qr-check-in.php`).

It is committed to the repository because WordCamp.org deploys do **not** run `composer install`.
The addon loads `qr-lib/vendor/autoload.php` on demand via
`CampTix_QR_Check_In::maybe_load_qr_library()`, and skips it when the library is already provided by
the global Composer autoloader (e.g. in a local `composer install` environment).

## Contents / versions
- chillerlan/php-qrcode 5.0.5 (MIT / Apache-2.0; used under MIT)
- chillerlan/php-settings-container 3.3.0 (MIT)

License files are retained under each package.

## Trimmed to reduce footprint (~656K → ~430K)
We only ever *generate* a QR (PNG, with SVG as a no-GD fallback), so non-runtime code was removed
from the upstream dist:

- **QR reader/decoder** — `chillerlan/php-qrcode/src/Decoder/`, `.../src/Detector/`, and the
  reader-only luminance-source classes in `.../src/Common/` (`GDLuminanceSource`,
  `IMagickLuminanceSource`, `LuminanceSourceAbstract`, `LuminanceSourceInterface`).
- **Unused output formats** in `chillerlan/php-qrcode/src/Output/` — Imagick, FPDF, EPS, the non-PNG
  GD writers (BMP/GIF/JPEG/WEBP), HTML markup, and the String/JSON outputs. **Kept:** the PNG
  (`QRGdImage` → `QRGdImagePNG`) and SVG (`QRMarkup` → `QRMarkupSVG`) chains plus their
  interface/abstract base classes.
- Package `README.md` / `NOTICE` and other non-runtime files.

The trims are safe because the addon only ever selects `GDIMAGE_PNG` or `MARKUP_SVG`; both paths are
verified to render after trimming.

## Updating
1. `composer require chillerlan/php-qrcode:^5` in a scratch directory.
2. Copy its `vendor/` over this one.
3. Re-apply the trims listed above.
4. Confirm a PNG **and** an SVG still render (`outputBase64 => false`).
