# Changelog

All notable changes to Notice Board (`noticeboard`) are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
the App Store shows the section whose heading matches the release version.

## [Unreleased]

## 0.5.2 – 2026-10-27

### Changed
- Talk: each person now receives announcements in a read-only group conversation
  ("📢") owned by the Notice Board bot account, instead of a one-to-one chat.
  There are no call or invite buttons, and the conversation notifies on every message.
  A deleted conversation is recreated; a person who left is added back.

## 0.5.1 – 2026-10-27

### Added
- Images inside the announcement text can be aligned left, centre or right
  (text flows around them), resized by dragging a corner or with preset sizes,
  and given a caption. On narrow screens a floated image moves to its own line.

## 0.5.0 – 2026-10-21

### Added
- Talk channel: a new per-announcement switch sends the announcement to the
  targeted people in Nextcloud Talk (only shown when Talk is installed).
  The author also receives a copy.
- Images can be inserted into the announcement text (button, paste or drag and drop).
  Images are served only to people who can see the announcement.

### Changed
- The publisher picker in the admin settings shows e-mail addresses / display
  names instead of raw account IDs (useful with LDAP/AD).

## 0.4.3 – 2026-10-07

### Changed
- Packaging only: version bump so that installations of the earlier 0.4.2 package
  are updated cleanly.

## 0.4.2 – 2026-10-07

### Added
- Drafts, scheduled and archived announcements carry a coloured status badge
  in the list and in the detail view.

### Fixed
- Unknown activities from other apps no longer cause errors in the activity sidebar
  (correct exception class on every supported Nextcloud version).

## 0.4.1 – 2026-10-07

### Added
- Category is picked from a list of twelve ready categories (or typed freely);
  each category has its own automatic cover colour and icon.

### Fixed
- Attachments are now verified to belong to the person who adds them.
- Phone photos no longer turn sideways when straightened in the cover editor.
- The admin settings menu icon was not displayed.
- Readers no longer receive the list of targeted groups; the detail view shows
  "Published by <display name>".

## 0.4.0 – 2026-10-07

### Added
- Cover editor: crop with ratio presets, rotate, flip, straighten, zoom,
  brightness / contrast / saturation, text on the cover, and a live dashboard preview.
- Automatic cover for announcements without an image.
- Spotlight widget settings for administrators (slide duration, all or pinned announcements).

### Changed
- A cover image is verified to belong to the person who sets it.

## 0.3.0 – 2026-10-05

### Added
- Cover focal point.

### Fixed
- "Edit" sometimes did nothing after closing the composer.
- Clearing the cover, schedule or category while editing was not saved.
- Administrators can edit announcements written by other publishers.

## 0.2.0 – 2026-09-24

### Added
- First release: rich-text composer with cover image, attachments and live preview;
  targeting to everyone or selected groups; publisher role managed by administrators;
  scheduled publication and automatic retirement; per-announcement notifications,
  activities, e-mail and comments; List, Card and Spotlight dashboard widgets;
  unified search; English and Turkish translations.
