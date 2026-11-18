# Change Log
All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

## [0.6.1] - 2026-11-18
- Restricted administrators with the Config tab can be given Server Settings (`server_setting/*`) when a root administrator saves their account. Existing restricted administrators with the Config tab receive Server Settings on upgrade.
- An instance without a root administrator designates its lowest-id active administrator as root on upgrade; `php artisan df:root_admin` changes it.
- Administrator accounts, and the roles, role assignments and service access that belong to administrators, are created, changed and deleted by the root administrator. Scheduled tasks that run as another administrator are saved by the root administrator.
- Scheduled tasks that change administrator accounts or their roles must run as a root administrator.
- Requests the restricted-administrator rules decline receive a JSON 403 in the standard DreamFactory error format.
- Stability and reliability improvements.
