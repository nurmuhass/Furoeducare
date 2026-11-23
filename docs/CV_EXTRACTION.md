# CV extraction and review

## Document content policy — 23 November 2026

Uploads, extraction and original downloads reject active/embedded/external-action PDF features, encryption and opaque object streams, including escaped PDF names. Some legitimate modern PDFs therefore require re-export as a plain text PDF, DOCX or manual onboarding. DOCX validation covers the whole ZIP package and XML/relationships with bounded expansion, rejecting macros, embedded objects, external templates, traversal, links and DTD/entities. Ordinary web/email hyperlinks are allowed. These structural checks are not antivirus or a complete sanitizer.

Previously stored PDF/DOCX originals undergo the same policy before download; valid originals stay byte-for-byte unchanged. The extraction child process is not an OS sandbox. See [security review limitations](SECURITY_REVIEW.md#compatibility-and-document-processing-limits).

Teachers can upload text-based PDF or DOCX CVs up to 5 MiB under **CV and Documents**. The upload checks the extension, detected MIME type, size and file structure. The original stays in private teacher storage under a random key. Each newly uploaded CV gets one extraction job in the same transaction as its metadata. Teachers can queue an older CV from the documents page.

## Running the worker

Use PHP 8.5 and MySQL 8.4. Enable GD, ZIP, XMLReader, Fileinfo and PDO MySQL in the active PHP installation. The CLI worker also uses PHP’s proc_open function. On Windows, use `php --ini` to locate the CLI config and enable `extension=gd` if it is commented out. Run the following from the project root after configuring the environment.

```powershell
composer install
php bin/database.php migrate
php bin/cv-worker.php --once
```

The worker must use the same database and private `TEACHER_DOCUMENT_DIR` as the web app. The default invocation processes up to ten jobs. Schedule `php bin/cv-worker.php` about once a minute with Windows Task Scheduler or Linux cron, using the application’s PHP installation and service account. The same command removes expired draft content and marks interrupted jobs after five minutes. Unexpected worker exits leave a job recoverable as a failed extraction on the next scheduled cleanup. Teachers can upload a replacement or continue manually. Worker output contains job counts and generic errors.

## What extraction can read

- The PDF parser reads selectable text locally. The PHP ZIP and XMLReader libraries read the main Word document XML. Each extraction runs in a separate PHP CLI process with a 128 MiB memory limit and a 30-second wall-clock deadline. [PrinsFrank PDF parser](https://github.com/PrinsFrank/pdfparser)
- The parser recognises common English headings for summaries, education, work, skills, subjects and certifications. It supports sections in any order, multiple entries, labelled role and employer lines, and some common date and separator styles.
- Multi-column reading order, tables, unusual headings, dates or entry layouts, and documents with little selectable text can leave values blank or give a value the wrong label. Password-protected or damaged files may fail. Image-only PDFs show an OCR message. OCR and external AI services are outside this release.
- A draft stores suggestions only. It does not store the full extracted CV text or infer contact information, verification, teaching levels, preferences or availability.

The versioned JSON schema lives in `CvDraft.php`. Version 1 uses `version`, nullable `summary`, and lists for `qualifications`, `experience`, `skills`, `subjects` and `certifications`. Qualifications contain nullable qualification, institution, field of study and completion year. Experience entries contain nullable position title, organisation, start year, end year and description. Each list accepts up to 25 items. Invalid types or fields are refused. Suggestions are always presented for teacher review, with absent sections and fields marked as missing.

## Correcting and saving suggestions

1. Upload a CV and wait for **Review CV suggestions** to appear. Use **Check extraction status** to refresh while the job is queued.
2. Check the editable suggestions against your CV. Correct a role or institution, add information you know, or use **Skip this suggestion** to omit a suggested entry. Keep a field blank if you are unsure. Enter each skill, subject and certification on its own line.
3. Confirm the review and choose **Save reviewed profile**. The save fills an empty biography and appends suggestions to the relevant sections. Existing biographies, teaching levels, records and declarations of no prior experience take priority. Matching entries and suggestions beyond the existing profile limits are skipped.
4. Use the regular profile editor to revise a previously saved value or finish a section. If a CV is scanned, unreadable or its draft expired, start from the manual editor or upload a text-based replacement.

An unapplied draft is available for seven days. Confirmation clears its JSON. Replacing or removing the original revokes access to the draft immediately, and deleting the old original's metadata removes its job. A draft never populates a public profile.
