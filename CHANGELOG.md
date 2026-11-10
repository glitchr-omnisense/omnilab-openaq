# Changelog

## [Unreleased]

### Added

- `policy.json`: the service's usage policy as data (limits, licence, credit, what a request needs), read at its pages on 2026-11-10 and named in `composer.json` (`extra.omnisense.policy`); the core honours it on every request.

### Fixed

- A variable no location within the radius measures is a series without points at the point
  asked, where it was left out: the list was shorter than the variables, and destructuring it by
  position took the wrong series (`SourceInterface::series()`: one per variable, in the order asked).
