# Decision: Project language English

**Date:** 2026-11-11

## Context

The WordPress plugin guideline requires one consistent project language for plugin description, UI texts and documentation. The plugin implements a protocol of the UJMW landscape, whose documentation, epics and skills are English.

## Decision

Project language is **English** (UI, plugin description, `README.md`, `/doc`). Code identifiers, code comments and AI skills are English anyway.

## Alternatives

- German (like WP-RemoteArticles and WP-PluginGallery): rejected, the plugin may later become part of the public UJMW landscape.

## Consequences

UI strings are plain English literals; translation readiness via `__()` is not implemented (see [backlog](../work/backlog.md)).
