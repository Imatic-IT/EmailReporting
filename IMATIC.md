# Imatic fork notes

This repository is Imatic's fork of the [MantisBT EmailReporting plugin](https://github.com/mantisbt-plugins/EmailReporting).
Everything Imatic-specific lives on the **`control_headers`** branch. Upstream is
merged into it by hand; it is never pulled over the branch.

Read this before merging upstream — `core/mail_api.php` carries most of our
changes and is the file that will conflict.

## What Imatic changed

| Area | Files | Why |
|---|---|---|
| Project routing by recipient address | `EmailReporting.php`, `core/mail_api.php` | Mantis issue 0083734. See below. |
| Windows-125x decoding (`9f89d7e`) | `core/Mail/Parser.php` | Upstream mapped `windows-1250` to `ISO-8859-2`, which silently dropped Czech letters. Decoded through `iconv` instead. |
| Email debug log (`5471c17`) | `EmailReporting.php`, `core/mail_api.php` | Dumps raw incoming mail for troubleshooting. |
| Duplicate attachments (`7bfd180`) | `core/mail_api.php` | The same attachment was added twice on some mail. |
| Variable interpolation fix (`d30b211`) | `pages/manage_config_edit.php` | |
| `print_category_option_list()` argument | `core/config_api.php` | The mailbox category was passed as an array, so a mailbox with no category yet failed with `ERROR_CATEGORY_NOT_FOUND` (#1502). |
| `gpc_get_string_array()` default | `pages/manage_mailbox_edit.php` | MantisBT 2.28 types the default as `array`, so upstream's `NULL` marker is a `TypeError` and the mailbox cannot be saved. |
| `EVENT_ERP_DESCRIPTION` chain value | `core/mail_api.php` | The params array was passed as the chain value. With no listener registered `event_type_chain()` returns its input unchanged, so the array leaked into `bugnote_add()`. Only reproducible with no plugin listening. |
| `mail_delete` honoured on IMAP | `core/mail_api.php` | `deleteMsg()` ran unconditionally; only the expunge was gated. Mail was flagged `\Deleted` even with `mail_delete` off. |

## Project routing by recipient address

`process_imap_folder()` is the folder-processing loop, factored out of
`process_mailbox()` so it can be called twice. With
`imap_createfolderstructure` on, the basefolder **root** is now processed as
well — previously only per-project subfolders were, so mail that was never
sorted into a folder was ignored.

Root mail is processed with no project override, which lets a listener decide:

```php
$p_overwrite_project_id = event_signal(
    'EVENT_ERP_PROJECT_DETERMINE',
    $p_overwrite_project_id,          // int, or FALSE when nothing decided yet
    array( $t_email, $this->_mailbox )
);
```

`EVENT_ERP_PROJECT_DETERMINE` is an `EVENT_TYPE_CHAIN` declared in
`EmailReporting.php`. Precedence is unchanged: a folder-derived project is
passed in as an `int` and listeners are expected to leave it alone; when nothing
routes, `add_bug()` still falls back to the mailbox default project.

The listener Imatic ships is in the separate
[`ImaticEmailReporting`](https://github.com/Imatic-IT/imatic-mantis-emailreporting)
plugin, which routes on `To` → `Cc` → `Bcc` → `Delivered-To`. This plugin works
on its own without it.

> Deploying this to an existing mailbox processes the **whole backlog sitting in
> the basefolder root** on the first run. Empty or archive it first.

## Merging upstream

```bash
git remote add upstream https://github.com/mantisbt-plugins/EmailReporting.git
git fetch upstream
git checkout control_headers
git merge upstream/master
```

Expect conflicts in `core/mail_api.php`. Then bump the submodule pointer in the
`imatic-mantis` superproject.

The Mantis upgrade script (`update-mantis`) does not touch this plugin — plugins
are submodules and are re-added as they are.
