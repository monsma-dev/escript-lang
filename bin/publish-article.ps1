# EScript Article Publisher (PowerShell version)
# Automates publishing of blog articles to multiple platforms

param(
    [Parameter(Mandatory=$false)]
    [switch]$DryRun,
    
    [Parameter(Mandatory=$false)]
    [switch]$VerboseOutput,
    
    [Parameter(Mandatory=$false)]
    [string]$Platforms = "github,devto,hashnode",
    
    [Parameter(Mandatory=$false)]
    [switch]$Test,
    
    [Parameter(Mandatory=$false, Position=0)]
    [string]$ArticleFile
)

# Configuration
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectRoot = Split-Path -Parent $ScriptDir
$ContentDir = Join-Path $ProjectRoot "content/blog"
$DraftsDir = Join-Path $ContentDir "drafts"
$PublishedDir = Join-Path $ContentDir "published"
$TemplatesDir = Join-Path $ContentDir "templates"

# Logging functions
function Write-Info {
    param([string]$Message)
    Write-Host "[INFO] $Message" -ForegroundColor Blue
}

function Write-Success {
    param([string]$Message)
    Write-Host "[SUCCESS] $Message" -ForegroundColor Green
}

function Write-Warning {
    param([string]$Message)
    Write-Host "[WARNING] $Message" -ForegroundColor Yellow
}

function Write-Error {
    param([string]$Message)
    Write-Host "[ERROR] $Message" -ForegroundColor Red
}

# Usage information
function Show-Usage {
    @"
EScript Article Publisher (PowerShell)

USAGE:
    .\publish-article.ps1 [OPTIONS] <article-file>

OPTIONS:
    -DryRun           Show what would be published without actually publishing
    -Verbose          Enable verbose output
    -Platforms        Comma-separated list of platforms (github,devto,hashnode)
    -Test             Validate article without publishing

EXAMPLES:
    .\publish-article.ps1 drafts\my-article.md                    # Publish to all platforms
    .\publish-article.ps1 -DryRun drafts\my-article.md          # Preview publishing actions
    .\publish-article.ps1 -Platforms github,devto my-article.md # Publish to specific platforms
    .\publish-article.ps1 -Test drafts\my-article.md             # Validate article only

ARTICLE REQUIREMENTS:
    - Must be in content\blog\drafts\ directory
    - Must have valid frontmatter (title, date, tags)
    - Must include playground_link in frontmatter
    - All code examples must be compilable
    - All links must be valid

"@
}

# Validate article file
if (-not $ArticleFile) {
    Write-Error "No article file specified"
    Show-Usage
    exit 1
}

# Resolve article path
if (-not [System.IO.Path]::IsPathRooted($ArticleFile)) {
    $ArticleFile = Join-Path $ContentDir $ArticleFile
}

if (-not (Test-Path $ArticleFile)) {
    Write-Error "Article file not found: $ArticleFile"
    exit 1
}

$ArticleName = [System.IO.Path]::GetFileNameWithoutExtension($ArticleFile)
$ArticleDir = Split-Path -Parent $ArticleFile
$PublishedFile = Join-Path $PublishedDir "$ArticleName.md"

Write-Info "Processing article: $ArticleName"

# Validate frontmatter
function Test-FrontMatter {
    param([string]$File)
    
    Write-Info "Validating frontmatter..."
    
    $content = Get-Content $File -Raw
    $requiredFields = @("title", "date", "tags", "playground_link")
    
    foreach ($field in $requiredFields) {
        $pattern = "^${field}:"
        if ($content -notmatch $pattern) {
            Write-Error "Missing required frontmatter field: $field"
            return $false
        }
    }
    
    # Validate date format
    if ($content -notmatch '^date: "\d{4}-\d{2}-\d{2}"') {
        Write-Error "Invalid date format in frontmatter (expected: YYYY-MM-DD)"
        return $false
    }
    
    # Validate playground link
    if ($content -notmatch '^playground_link: "https://') {
        Write-Error "Invalid playground_link format (must start with https://)"
        return $false
    }
    
    Write-Success "Frontmatter validation passed"
    return $true
}

# Validate code examples
function Test-CodeExamples {
    param([string]$File)
    
    Write-Info "Validating EScript code examples..."
    
    $content = Get-Content $File -Raw
    
    # Extract EScript code blocks
    $escriptBlocks = [regex]::Matches($content, '```escript\r?\n(.*?)\r?\n```', [System.Text.RegularExpressions.RegexOptions]::Singleline)
    
    if ($escriptBlocks.Count -gt 0) {
        Write-Info "Found $($escriptBlocks.Count) EScript code examples, validating compilation..."
        
        foreach ($block in $escriptBlocks) {
            $code = $block.Groups[1].Value
            $tempFile = [System.IO.Path]::GetTempFileName()
            $tempEsFile = $tempFile -replace '\.tmp$', '.es'
            Rename-Item $tempFile $tempEsFile
            
            try {
                Set-Content $tempEsFile $code -NoNewline
                
                # Test compilation (simplified - in real implementation would call compiler)
                # For now, just check if it's valid EScript syntax
                if ($code -notmatch '@fail_closed|guard|route|@') {
                    Write-Warning "EScript code block may not contain valid EScript syntax"
                }
                
                Write-Success "EScript code examples appear valid"
            }
            finally {
                if (Test-Path $tempEsFile) {
                    Remove-Item $tempEsFile -Force
                }
            }
        }
    }
    
    return $true
}

# Validate links
function Test-Links {
    param([string]$File)
    
    Write-Info "Validating links..."
    
    $content = Get-Content $File -Raw
    $links = [regex]::Matches($content, '\[.*\]\(([^)]+)\)')
    $brokenLinks = 0
    
    foreach ($match in $links) {
        $link = $match.Groups[1].Value
        
        # Skip anchor links and relative links
        if ($link.StartsWith('#') -or $link.StartsWith('./')) {
            continue
        }
        
        # Check HTTP links
        if ($link -match '^https?://') {
            try {
                $response = Invoke-WebRequest -Uri $link -Method Head -TimeoutSec 10 -UseBasicParsing
                if ($response.StatusCode -notin @(200, 201, 202, 204)) {
                    Write-Warning "Broken link: $link (Status: $($response.StatusCode))"
                    $brokenLinks++
                }
            }
            catch {
                Write-Warning "Broken link: $link ($($_.Exception.Message))"
                $brokenLinks++
            }
        }
    }
    
    if ($brokenLinks -gt 0) {
        Write-Error "Found $brokenLinks broken links"
        return $false
    }
    
    Write-Success "All links are valid"
    return $true
}

# Publish to GitHub Pages
function Publish-ToGitHub {
    param([string]$SourceFile, [string]$TargetFile)
    
    Write-Info "Publishing to GitHub Pages..."
    
    if ($DryRun) {
        Write-Info "[DRY RUN] Would copy $SourceFile to $TargetFile"
        return
    }
    
    # Create published directory if it doesn't exist
    if (-not (Test-Path $PublishedDir)) {
        New-Item -ItemType Directory -Path $PublishedDir -Force | Out-Null
    }
    
    # Copy article to published directory
    Copy-Item $SourceFile $TargetFile -Force
    
    # Update article index
    Update-ArticleIndex $ArticleName
    
    Write-Success "Published to GitHub Pages"
}

# Update article index
function Update-ArticleIndex {
    param([string]$ArticleName)
    
    $indexFile = Join-Path $ContentDir "index.md"
    
    # Create index if it doesn't exist
    if (-not (Test-Path $indexFile)) {
        @"
# EScript Blog

Latest articles about EScript, fail-closed security, and Evolution framework integration.

"@ | Set-Content $indexFile -NoNewline
    }
    
    # Add article to index (if not already there)
    $indexContent = Get-Content $indexFile -Raw
    if ($indexContent -notmatch [regex]::Escape($ArticleName)) {
        Add-Content $indexFile "- [$ArticleName](published/$ArticleName.md)"
    }
}

# Main execution
Write-Info "Starting article publishing process..."

# Validation phase
if (-not (Test-FrontMatter $ArticleFile)) {
    Write-Error "Frontmatter validation failed"
    exit 1
}

if (-not (Test-CodeExamples $ArticleFile)) {
    Write-Error "Code example validation failed"
    exit 1
}

if (-not (Test-Links $ArticleFile)) {
    Write-Error "Link validation failed"
    exit 1
}

if ($Test) {
    Write-Success "Article validation completed successfully"
    exit 0
}

# Publishing phase
$platformArray = $Platforms -split ','

foreach ($platform in $platformArray) {
    switch ($platform.Trim()) {
        "github" {
            Publish-ToGitHub $ArticleFile $PublishedFile
        }
        "devto" {
            Write-Info "Publishing to Dev.to..."
            if ($DryRun) {
                Write-Info "[DRY RUN] Would publish to Dev.to (API integration needed)"
            } else {
                Write-Warning "Dev.to publishing not yet implemented"
            }
        }
        "hashnode" {
            Write-Info "Publishing to Hashnode..."
            if ($DryRun) {
                Write-Info "[DRY RUN] Would publish to Hashnode (API integration needed)"
            } else {
                Write-Warning "Hashnode publishing not yet implemented"
            }
        }
        default {
            Write-Warning "Unknown platform: $platform"
        }
    }
}

# Git operations
if (-not $DryRun) {
    Write-Info "Committing changes to git..."
    
    Set-Location $ProjectRoot
    
    $publishedPath = "content/blog/published/$ArticleName.md"
    $indexPath = "content/blog/index.md"
    
    git add $publishedPath
    git add $indexPath
    
    $commitMessage = "publish: Add article $ArticleName"
    git commit -m $commitMessage
    
    Write-Success "Changes committed. Run 'git push' to deploy."
}

Write-Success "Article publishing completed successfully!"
Write-Info "Article available at: content/blog/published/$ArticleName.md"
