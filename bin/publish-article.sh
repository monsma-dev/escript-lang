#!/bin/bash

# EScript Article Publisher
# Automates publishing of blog articles to multiple platforms

set -euo pipefail

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
CONTENT_DIR="$PROJECT_ROOT/content/blog"
DRAFTS_DIR="$CONTENT_DIR/drafts"
PUBLISHED_DIR="$CONTENT_DIR/published"
TEMPLATES_DIR="$CONTENT_DIR/templates"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Usage information
usage() {
    cat << EOF
EScript Article Publisher

USAGE:
    $0 [OPTIONS] <article-file>

OPTIONS:
    -h, --help          Show this help message
    -d, --dry-run       Show what would be published without actually publishing
    -v, --verbose       Enable verbose output
    -p, --platforms     Comma-separated list of platforms (github,devto,hashnode)
    -t, --test          Validate article without publishing

EXAMPLES:
    $0 drafts/my-article.md                    # Publish to all platforms
    $0 --dry-run drafts/my-article.md          # Preview publishing actions
    $0 --platforms github,devto my-article.md # Publish to specific platforms
    $0 --test drafts/my-article.md             # Validate article only

ARTICLE REQUIREMENTS:
    - Must be in content/blog/drafts/ directory
    - Must have valid frontmatter (title, date, tags)
    - Must include playground_link in frontmatter
    - All code examples must be compilable
    - All links must be valid

EOF
}

# Parse command line arguments
DRY_RUN=false
VERBOSE=false
PLATFORMS="github,devto,hashnode"
TEST_ONLY=false

while [[ $# -gt 0 ]]; do
    case $1 in
        -h|--help)
            usage
            exit 0
            ;;
        -d|--dry-run)
            DRY_RUN=true
            shift
            ;;
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        -p|--platforms)
            PLATFORMS="$2"
            shift 2
            ;;
        -t|--test)
            TEST_ONLY=true
            shift
            ;;
        -*)
            log_error "Unknown option: $1"
            usage
            exit 1
            ;;
        *)
            ARTICLE_FILE="$1"
            shift
            ;;
    esac
done

# Validate article file
if [[ -z "${ARTICLE_FILE:-}" ]]; then
    log_error "No article file specified"
    usage
    exit 1
fi

# Resolve article path
if [[ ! "$ARTICLE_FILE" == /* ]]; then
    ARTICLE_FILE="$CONTENT_DIR/$ARTICLE_FILE"
fi

if [[ ! -f "$ARTICLE_FILE" ]]; then
    log_error "Article file not found: $ARTICLE_FILE"
    exit 1
fi

# Extract article info
ARTICLE_NAME=$(basename "$ARTICLE_FILE" .md)
ARTICLE_DIR=$(dirname "$ARTICLE_FILE")
PUBLISHED_FILE="$PUBLISHED_DIR/$ARTICLE_NAME.md"

log_info "Processing article: $ARTICLE_NAME"

# Validate frontmatter
validate_frontmatter() {
    local file="$1"
    
    log_info "Validating frontmatter..."
    
    # Check for required frontmatter fields
    local required_fields=("title" "date" "tags" "playground_link")
    
    for field in "${required_fields[@]}"; do
        if ! grep -q "^$field:" "$file"; then
            log_error "Missing required frontmatter field: $field"
            return 1
        fi
    done
    
    # Validate date format
    if ! grep -q '^date: "[0-9]{4}-[0-9]{2}-[0-9]{2}"' "$file"; then
        log_error "Invalid date format in frontmatter (expected: YYYY-MM-DD)"
        return 1
    fi
    
    # Validate playground link
    local playground_link
    playground_link=$(grep "^playground_link:" "$file" | cut -d'"' -f2)
    if [[ ! "$playground_link" =~ ^https:// ]]; then
        log_error "Invalid playground_link format (must start with https://)"
        return 1
    fi
    
    log_success "Frontmatter validation passed"
    return 0
}

# Validate code examples
validate_code_examples() {
    local file="$1"
    
    log_info "Validating EScript code examples..."
    
    # Extract EScript code blocks
    local temp_escript_file
    temp_escript_file=$(mktemp --suffix=.es)
    
    # Find all EScript code blocks and validate them
    awk '/^```escript$/,/^```$/{if(/^```$/)next;print}' "$file" > "$temp_escript_file"
    
    if [[ -s "$temp_escript_file" ]]; then
        log_info "Found EScript code examples, validating compilation..."
        
        # Compile with EScript compiler
        if ! cd "$PROJECT_ROOT" && node playground/compiler.js "$temp_escript_file" > /dev/null 2>&1; then
            log_error "EScript code examples failed compilation"
            rm -f "$temp_escript_file"
            return 1
        fi
        
        log_success "EScript code examples compile successfully"
    fi
    
    rm -f "$temp_escript_file"
    return 0
}

# Validate links
validate_links() {
    local file="$1"
    
    log_info "Validating links..."
    
    # Extract all markdown links
    local links
    links=$(grep -oE '\[.*\]\([^)]+\)' "$file" | cut -d'(' -f2 | cut -d')' -f1)
    
    local broken_links=0
    
    for link in $links; do
        # Skip anchor links and relative links
        if [[ "$link" == ^# ]] || [[ "$link" == ^./ ]]; then
            continue
        fi
        
        # Check HTTP links
        if [[ "$link" =~ ^https?:// ]]; then
            if ! curl -s -o /dev/null -w "%{http_code}" "$link" | grep -q "^[23]"; then
                log_warning "Broken link: $link"
                ((broken_links++))
            fi
        fi
    done
    
    if [[ $broken_links -gt 0 ]]; then
        log_error "Found $broken_links broken links"
        return 1
    fi
    
    log_success "All links are valid"
    return 0
}

# Publish to GitHub Pages
publish_to_github() {
    local source_file="$1"
    local target_file="$2"
    
    log_info "Publishing to GitHub Pages..."
    
    if [[ "$DRY_RUN" == "true" ]]; then
        log_info "[DRY RUN] Would copy $source_file to $target_file"
        return 0
    fi
    
    # Create published directory if it doesn't exist
    mkdir -p "$PUBLISHED_DIR"
    
    # Copy article to published directory
    cp "$source_file" "$target_file"
    
    # Update article index
    update_article_index "$ARTICLE_NAME"
    
    log_success "Published to GitHub Pages"
}

# Update article index
update_article_index() {
    local article_name="$1"
    local index_file="$CONTENT_DIR/index.md"
    
    # Create index if it doesn't exist
    if [[ ! -f "$index_file" ]]; then
        cat > "$index_file" << EOF
# EScript Blog

Latest articles about EScript, fail-closed security, and Evolution framework integration.

EOF
    fi
    
    # Add article to index (if not already there)
    if ! grep -q "$article_name" "$index_file"; then
        echo "- [$article_name](published/$article_name.md)" >> "$index_file"
    fi
}

# Publish to Dev.to (placeholder)
publish_to_devto() {
    local file="$1"
    
    log_info "Publishing to Dev.to..."
    
    if [[ "$DRY_RUN" == "true" ]]; then
        log_info "[DRY RUN] Would publish to Dev.to (API integration needed)"
        return 0
    fi
    
    # TODO: Implement Dev.to API integration
    log_warning "Dev.to publishing not yet implemented"
}

# Publish to Hashnode (placeholder)
publish_to_hashnode() {
    local file="$1"
    
    log_info "Publishing to Hashnode..."
    
    if [[ "$DRY_RUN" == "true" ]]; then
        log_info "[DRY RUN] Would publish to Hashnode (API integration needed)"
        return 0
    fi
    
    # TODO: Implement Hashnode API integration
    log_warning "Hashnode publishing not yet implemented"
}

# Main execution
main() {
    log_info "Starting article publishing process..."
    
    # Validation phase
    if ! validate_frontmatter "$ARTICLE_FILE"; then
        log_error "Frontmatter validation failed"
        exit 1
    fi
    
    if ! validate_code_examples "$ARTICLE_FILE"; then
        log_error "Code example validation failed"
        exit 1
    fi
    
    if ! validate_links "$ARTICLE_FILE"; then
        log_error "Link validation failed"
        exit 1
    fi
    
    if [[ "$TEST_ONLY" == "true" ]]; then
        log_success "Article validation completed successfully"
        exit 0
    fi
    
    # Publishing phase
    IFS=',' read -ra PLATFORM_ARRAY <<< "$PLATFORMS"
    
    for platform in "${PLATFORM_ARRAY[@]}"; do
        case "$platform" in
            github)
                publish_to_github "$ARTICLE_FILE" "$PUBLISHED_FILE"
                ;;
            devto)
                publish_to_devto "$ARTICLE_FILE"
                ;;
            hashnode)
                publish_to_hashnode "$ARTICLE_FILE"
                ;;
            *)
                log_warning "Unknown platform: $platform"
                ;;
        esac
    done
    
    # Git operations
    if [[ "$DRY_RUN" == "false" ]]; then
        log_info "Committing changes to git..."
        
        cd "$PROJECT_ROOT"
        git add "content/blog/published/$ARTICLE_NAME.md"
        git add "content/blog/index.md"
        
        local commit_message="publish: Add article $ARTICLE_NAME"
        git commit -m "$commit_message"
        
        log_success "Changes committed. Run 'git push' to deploy."
    fi
    
    log_success "Article publishing completed successfully!"
    log_info "Article available at: content/blog/published/$ARTICLE_NAME.md"
}

# Run main function
main "$@"
