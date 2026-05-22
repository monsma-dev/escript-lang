# EScript Blog — Content as Code

This directory contains technical articles about EScript, written in Markdown and managed as code.

## Structure

```
content/blog/
├── README.md                 # This file
├── drafts/                   # Work-in-progress articles
├── published/                # Published articles
└── templates/                # Article templates
    ├── technical-post.md     # Technical deep-dive template
    └── announcement.md       # Release/update template
```

## Publishing Workflow

1. **Write**: Create article in `drafts/` using templates
2. **Review**: Test compilation examples and validate links
3. **Publish**: Move to `published/` and run `bin/publish-article.sh`
4. **Deploy**: GitHub Actions automatically deploy to GitHub Pages

## Article Guidelines

- **Technical Focus**: Target senior architects and developers
- **Code Examples**: Include working EScript examples
- **Playground Links**: Always link to live playground demos
- **TL;DR**: Start with concise summary for busy readers
- **How-to Sections**: Practical implementation guidance

## Automation

- `bin/publish-article.sh` - Publishes to multiple platforms
- GitHub Actions - Auto-deploy to GitHub Pages blog
- AI Bridge - Sync with Evolution framework documentation

## Topics

- Fail-Closed security patterns
- IR schema evolution and backwards compatibility
- Framework adapters (Laravel, Symfony, Evolution)
- Compliance automation and guard patterns
- Real-time linting and validation
