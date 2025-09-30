# Locker Manager

A WordPress plugin to create and manage lockers with city selection, email confirmation, and protected admin listing.

## Features  
- Locker number generation (starts at `100501` per city).
- City selection: San Pedro Sula, Tegucigalpa, Other.
- Email confirmation with activation link.
- Protected locker list page (username/password set in plugin settings).
- Anti-bot checkbox before creating a locker.
- Styled form and success message.

## Installation
1. Download or clone this repository.
2. Upload the folder `locker-manager` to your WordPress `/wp-content/plugins/` directory.
3. Activate **Locker Manager** in the WordPress admin panel.
4. Go to **Settings → Locker Settings** to configure access credentials.

## Shortcodes
- `[locker_form]` → Displays the form to create a new locker.
- `[locker_list]` → Displays the locker listing (protected by login).

## Author
Developed by [kenaldertech](https://github.com/kenaldertech)
