# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.1] - 2026-04-10

### Fixed

- Exclude `skill/` directory from Composer dist archives. It contains dev-only helper scripts and reference docs that have no runtime purpose for package consumers and should not ship in the `vendor/` folder.
- Exclude `CLAUDE.md` from Composer dist archives. It contains dev-only project instructions with no runtime purpose for package consumers.

## [0.1.0] - 2026-03-02

### Added

- PHP 7.4+ Data Transfer Objects (DTOs) for the Model Context Protocol (MCP) 2025-11-25 specification
- `fromArray()` static factory methods for deserializing arrays into typed DTOs
- `toArray()` methods for serializing DTOs to arrays suitable for `json_encode()`
- Factory classes for union/polymorphic type resolution
- Class-based enums for MCP enumerated values (PHP 7.4 compatible)
- Complete MCP domain coverage: Server (Tools, Resources, Prompts, Logging), Client (Sampling, Elicitation, Roots), Common (JSON-RPC, Protocol, Content)
- JSON-RPC 2.0 message types (Request, Notification, Result, Error)
- PSR-4 autoloading under `WP\McpSchema\` namespace
- PHPStan level max static analysis validation
