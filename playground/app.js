/**
 * EScript Playground — UI Controller
 */

const EXAMPLES = {
    'basic-api': `// Basic REST API — DTOs, Guards, Services, Routes

dto UserDto {
    id: int;
    name: string;
    email: string;
    role: string = "user";
    avatar_url: string?;
}

dto CreateUserRequest {
    name: string;
    email: string;
    password: string;
}

guard RateLimitGuard {
    tier: @rust;
    input: RateLimitRequest;
    output: RateLimitDecision;
    fail_mode: closed;
}

@tier(php)
@fail_closed
service UserService implements UserServiceInterface {
    inject db: DatabaseConnection;
    inject hasher: PasswordHasher;
    guard RateLimitGuard;

    pub fn create(request: CreateUserRequest) -> Result<UserDto, ApiError> {
        // Adapter emits framework-specific code
    }

    pub fn findById(id: int) -> UserDto? {
        // Returns nullable DTO
    }
}

@auth(none)
@rate_limit(sliding)
route GET "/api/v1/users"
    -> @php UserController@list
    {
        middleware: [RateLimitMiddleware];
        dto: UserDto;
    };

@auth(bearer)
@rate_limit(strict)
route POST "/api/v1/users"
    -> @php UserController@store
    {
        middleware: [AuthMiddleware, RateLimitMiddleware];
        dto: CreateUserRequest;
    };

@auth(bearer)
route DELETE "/api/v1/users/{id}"
    -> @php UserController@destroy
    {
        middleware: [AuthMiddleware];
    };`,

    'compliance': `// Compliance Automation — Self-Healing Guards

dto ViolationReport {
    file_path: string;
    rule_id: string;
    severity: string;
    message: string;
    suggested_fix: string?;
    auto_fixable: bool = false;
}

dto DispatchResult {
    job_id: string;
    status: string;
    dispatched_at: string;
    estimated_ms: int;
}

@trigger(on: "layer_violation")
@action(dispatch: "rector_auto_fix")
@condition(severity: "error", auto_fixable: true)
guard LayerViolationGuard {
    tier: @rust;
    input: ViolationReport;
    output: DispatchResult;
    fail_mode: closed;
}

@trigger(on: "provision_request")
@condition(cost_exceeds_ceiling: true)
guard SpendingCeilingGuard {
    tier: @rust;
    input: SpendRequest;
    output: SpendDecision;
    fail_mode: closed;
    ceiling: 20.00;
}

@tier(php)
@fail_closed
service ComplianceService implements ComplianceServiceInterface {
    inject pool: AnalysisPoolClient;
    inject dispatcher: JobDispatcher;
    guard LayerViolationGuard;
    guard SpendingCeilingGuard;

    pub fn analyzeFile(filePath: string) -> ViolationReport[] {
        // Runs PHPStan/Psalm layer rules
    }

    pub fn dispatchAutoFix(violation: ViolationReport) -> Result<DispatchResult, DispatchError> {
        // Dispatches rector auto-fix job
    }
}

@auth(none)
@rate_limit(sliding)
route GET "/api/v1/compliance/pool-status"
    -> @php ComplianceController@poolStatus
    {
        middleware: [RateLimitMiddleware];
    };

@auth(bearer)
@rate_limit(strict)
route POST "/api/v1/compliance/auto-fix"
    -> @php ComplianceController@dispatchAutoFix
    {
        middleware: [AdminAuthMiddleware, RateLimitMiddleware];
        dto: DispatchResult;
    };`,

    'custom': `// Write your own EScript code here!

dto MyDto {
    id: int;
    name: string;
}

guard MyGuard {
    tier: @rust;
    input: MyDto;
    output: MyDto;
    fail_mode: closed;
}

@auth(bearer)
route GET "/api/hello"
    -> @php HelloController@index
    {
        middleware: [AuthMiddleware];
        dto: MyDto;
    };`,
};

// ─── DOM ─────────────────────────────────────────────────────────────────────

const editor = document.getElementById('editor');
const irOutput = document.getElementById('irOutput');
const errorsOutput = document.getElementById('errorsOutput');
const astOutput = document.getElementById('astOutput');
const tokensOutput = document.getElementById('tokensOutput');
const compileBtn = document.getElementById('compileBtn');
const exampleSelect = document.getElementById('exampleSelect');
const statusEl = document.getElementById('status');
const compileTimeEl = document.getElementById('compileTime');
const lineCountEl = document.getElementById('lineCount');

// ─── Tab Switching ───────────────────────────────────────────────────────────

document.querySelectorAll('.output-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.output-tab').forEach(t => {
            t.classList.remove('tab-active');
            t.classList.add('tab-inactive');
        });
        tab.classList.remove('tab-inactive');
        tab.classList.add('tab-active');

        document.querySelectorAll('.output-panel').forEach(p => p.classList.add('hidden'));
        document.getElementById('output-' + tab.dataset.tab).classList.remove('hidden');
    });
});

// ─── Compile ─────────────────────────────────────────────────────────────────

function compile() {
    const source = editor.value;
    if (!source.trim()) {
        irOutput.textContent = 'No source code to compile.';
        return;
    }

    try {
        const result = window.EScriptCompiler.compile(source);

        // IR
        if (result.ir) {
            irOutput.textContent = JSON.stringify(result.ir, null, 2);
        } else {
            irOutput.textContent = 'Compilation failed. See Errors tab.';
        }

        // Errors
        if (result.errors.length > 0) {
            errorsOutput.textContent = result.errors.join('\n');
            errorsOutput.className = errorsOutput.className.replace('text-green-400', 'text-red-400');
        } else {
            errorsOutput.textContent = '✓ No errors — all fail-closed rules pass.';
            errorsOutput.className = errorsOutput.className.replace('text-red-400', 'text-green-400');
        }

        // AST
        astOutput.textContent = JSON.stringify(result.ast, null, 2);

        // Tokens
        const tokenLines = result.tokens
            .filter(t => t.type !== 'EOF')
            .map(t => `${t.line}:${t.col}\t${t.type.padEnd(12)}\t${t.value}`)
            .join('\n');
        tokensOutput.textContent = `Line:Col\tType\t\tValue\n${'─'.repeat(50)}\n${tokenLines}`;

        // Status
        statusEl.textContent = result.errors.length > 0
            ? `Failed: ${result.errors.length} error(s)`
            : `Compiled: ${result.ast.length} declaration(s)`;
        compileTimeEl.textContent = `${result.elapsed}ms`;

        // Switch to errors tab if there are errors
        if (result.errors.length > 0) {
            document.querySelector('[data-tab="errors"]').click();
        }

    } catch (e) {
        irOutput.textContent = '';
        errorsOutput.textContent = 'PARSE ERROR: ' + e.message;
        errorsOutput.className = errorsOutput.className.replace('text-green-400', 'text-red-400');
        statusEl.textContent = 'Parse error';
        document.querySelector('[data-tab="errors"]').click();
    }
}

compileBtn.addEventListener('click', compile);

// Ctrl+Enter to compile
editor.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        compile();
    }
    // Tab key inserts spaces
    if (e.key === 'Tab') {
        e.preventDefault();
        const start = editor.selectionStart;
        const end = editor.selectionEnd;
        editor.value = editor.value.substring(0, start) + '    ' + editor.value.substring(end);
        editor.selectionStart = editor.selectionEnd = start + 4;
    }
});

// ─── Real-time Linter ────────────────────────────────────────────────────────

const lintIcon = document.getElementById('lintIcon');
const lintStatus = document.getElementById('lintStatus');
let lintTimer = null;

function lint() {
    const source = editor.value;
    if (!source.trim()) {
        setLintState('empty', 'No code');
        return;
    }

    try {
        const tokens = window.EScriptCompiler.tokenize(source);
        const ast = window.EScriptCompiler.parse(tokens);
        const errors = window.EScriptCompiler.validate(ast);

        if (errors.length === 0) {
            setLintState('ok', `✓ ${ast.length} declaration${ast.length !== 1 ? 's' : ''} — no issues`);
        } else {
            setLintState('error', `${errors.length} issue${errors.length !== 1 ? 's' : ''}: ${errors[0]}`);
        }
    } catch (e) {
        const msg = e.message.replace(/^\[.*?\]\s*/, '');
        setLintState('error', `Syntax: ${msg}`);
    }
}

function setLintState(state, message) {
    lintStatus.textContent = message;
    if (state === 'ok') {
        lintIcon.textContent = '●';
        lintIcon.className = 'text-green-400';
        lintStatus.className = 'text-gray-400';
    } else if (state === 'error') {
        lintIcon.textContent = '●';
        lintIcon.className = 'text-red-400';
        lintStatus.className = 'text-red-400';
    } else {
        lintIcon.textContent = '●';
        lintIcon.className = 'text-gray-600';
        lintStatus.className = 'text-gray-500';
    }
}

// Line count + debounced lint
editor.addEventListener('input', () => {
    const lines = editor.value.split('\n').length;
    lineCountEl.textContent = `${lines} line${lines !== 1 ? 's' : ''}`;

    clearTimeout(lintTimer);
    lintTimer = setTimeout(lint, 300);
});

// ─── Example Loader ──────────────────────────────────────────────────────────

exampleSelect.addEventListener('change', () => {
    const key = exampleSelect.value;
    if (EXAMPLES[key]) {
        editor.value = EXAMPLES[key];
        editor.dispatchEvent(new Event('input'));
    }
});

// ─── Init ────────────────────────────────────────────────────────────────────

editor.value = EXAMPLES['basic-api'];
editor.dispatchEvent(new Event('input'));
lint();
