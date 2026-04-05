# 📚 2FA-Vault Documentation

Welcome! This documentation is organized into four main categories. Choose based on what you need:

## 🏗️ [Architecture](architecture/)
System design, codebase structure, data flows, and technical decisions.

**Best for:** Understanding how the system works, navigating the codebase, learning about design choices
- [System Architecture](architecture/system-architecture.md) — Layered architecture, API design, data flows
- [Codebase Summary](architecture/codebase-summary.md) — Directory structure and key files
- [Project Overview & Design Rationale](architecture/project-overview-pdr.md) — Vision and design principles

## 🛠️ [Development](development/)
Code standards, conventions, security guidelines, and best practices.

**Best for:** Writing code, understanding patterns, learning security requirements
- [Code Standards & Conventions](development/code-standards.md) — PHP/Vue patterns, testing, naming
- [Security Guidelines](development/security-guidelines.md) — Threat model, best practices

## 📖 [Guides](guides/)
Setup instructions, deployment, migration guides, and how-to documentation.

**Best for:** Getting started, deploying, migrating data, contributing to the project
- [Deployment Guide](guides/deployment-guide.md) — Docker, Kubernetes, and traditional deployment
- [Migration from 2FAuth](guides/migration-from-2fauth.md) — Upgrade instructions
- [Contributing Guide](guides/contributing.md) — Contribution workflow

## 📋 [Reference](reference/)
Roadmap, changelog, API documentation, and project planning documents.

**Best for:** Project planning, tracking progress, understanding what changed, API integration
- [API Documentation](reference/api-documentation.md) — Complete API reference for all endpoints
- [Roadmap](reference/roadmap.md) — Planned features and timeline
- [Changelog](reference/changelog.md) — Version history
- [Project Plan](reference/project-plan.md) — Milestones and phases

---

## Quick Links by Role

### 👨‍💻 I'm a Developer
1. Start with [CLAUDE.md](../CLAUDE.md) in the repo root
2. Read [Code Standards](development/code-standards.md) for patterns
3. Reference [System Architecture](architecture/system-architecture.md) for design
4. Check [Security Guidelines](development/security-guidelines.md) before implementing features

### 🔍 I'm Understanding the Codebase
1. Read [Codebase Summary](architecture/codebase-summary.md) for file structure
2. Explore [System Architecture](architecture/system-architecture.md) for data flows
3. Review [Project Overview](architecture/project-overview-pdr.md) for design rationale

### 📦 I'm Migrating from 2FAuth
1. Follow [Migration Guide](guides/migration-from-2fauth.md) step-by-step
2. Reference [Security Guidelines](development/security-guidelines.md) for E2EE
3. Check [Roadmap](reference/roadmap.md) for planned features

### 🚀 I'm Deploying to Production
1. Read [Deployment Guide](guides/deployment-guide.md) for Docker/Kubernetes/traditional setup
2. Review [API Documentation](reference/api-documentation.md) for endpoint details
3. Check [Security Guidelines](development/security-guidelines.md) before going live

### 🤝 I Want to Contribute
1. Read [Contributing Guide](guides/contributing.md)
2. Review [Code Standards](development/code-standards.md)
3. Check [Roadmap](reference/roadmap.md) for available tasks

---

## 📍 Context Compaction Reminder

After Claude Code context is compacted, always read these first to rebuild context:
1. [docs/architecture/system-architecture.md](architecture/system-architecture.md) — System design
2. [docs/architecture/codebase-summary.md](architecture/codebase-summary.md) — File structure
3. [docs/development/code-standards.md](development/code-standards.md) — Coding patterns
4. [docs/architecture/project-overview-pdr.md](architecture/project-overview-pdr.md) — Design decisions

Then refer to [CLAUDE.md](../CLAUDE.md) for the quick reference.
