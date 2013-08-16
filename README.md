php-mtt-import
==============

Imports Mail plain text into a http://www.mytinytodo.net installation:

- Reads mails from configured imap mailbox (inbox)
- Checks sender mail address
- Connect to myTinyTodo instance via mysql
- Import mail and new tags into configured list
- Deletes the imported mails from the previously connected mailbox


## Usage
- Update configuration to your needs (see top of todoimport.php)
- E.g. run todoimport.php with cronjob


## Limitation
- This script only imports the plaintext version and ignores any html or attachment content
- Priority is not supported, but you can add tags by adding #tag to your subject
