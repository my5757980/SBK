FPDF 1.86 by Olivier Plathey - http://www.fpdf.org

Vendored rather than installed: this is shared hosting with no composer and
no PDF binary of any kind, and the lot pages needed a real PDF instead of the
browser print dialogue they were opening.

Only fpdf.php and the four Helvetica metrics files are kept - the tutorials,
documentation and unused font families are not. Scanned before deploying: no
eval, no base64_decode, no shell access, no network calls; it opens files only
to read font metrics and the images it is given.

License: permissive, use for any purpose. See http://www.fpdf.org/en/script/
