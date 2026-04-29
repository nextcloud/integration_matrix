<!--
SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Element/Matrix integration into Nextcloud

This integration lets you send files to an Element/Matrix conversation directly from Nextcloud Files.

## 🔧 Configuration

### User settings

The account configuration happens in the "Connected accounts" user settings section.
It requires to get a personal access token in your Element settings.

A link to the "Connected accounts" user settings section will be displayed in the widget
for users who didn't configure an Element/Matrix account.

### Admin settings

There also is a "Connected accounts" **admin** settings section if you want to allow
your Nextcloud users to use OAuth to authenticate to a specific Matrix instance.
