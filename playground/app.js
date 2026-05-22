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

        // Dashboard
        renderDashboard(result.ir, result.ast, result.errors);

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

// ─── Compliance Dashboard ────────────────────────────────────────────────────

const dashboardContent = document.getElementById('dashboardContent');

function renderDashboard(ir, ast, errors) {
    if (!ir && errors.length === 0 && ast.length === 0) {
        dashboardContent.innerHTML = '<p class="text-gray-500 text-sm">Compile to see compliance dashboard.</p>';
        return;
    }

    const guards = ir?.guards || [];
    const routes = ir?.routes || [];
    const services = ir?.services || [];
    const dtos = ir?.dtos || [];

    // Guard analysis
    const totalGuards = guards.length;
    const failClosed = guards.filter(g => g.fail_mode === 'closed').length;
    const failOpen = guards.filter(g => g.fail_mode === 'open').length;
    const reactive = guards.filter(g => g.trigger).length;
    const unsafeAck = guards.filter(g => g.unsafe_acknowledged).length;

    // Route analysis
    const totalRoutes = routes.length;
    const authedRoutes = routes.filter(r => r.auth && r.auth !== 'none').length;
    const mutating = routes.filter(r => ['POST','PUT','DELETE','PATCH'].includes(r.method)).length;
    const mutatingAuthed = routes.filter(r =>
        ['POST','PUT','DELETE','PATCH'].includes(r.method) && r.auth && r.auth !== 'none'
    ).length;

    // Security score
    const checks = [];
    checks.push({ name: 'All guards fail-closed', pass: failOpen === 0 });
    checks.push({ name: 'No unsafe without acknowledgment', pass: failOpen === 0 || failOpen === unsafeAck });
    checks.push({ name: 'Mutating routes authenticated', pass: mutating === 0 || mutating === mutatingAuthed });
    checks.push({ name: 'Zero compile errors', pass: errors.length === 0 });
    checks.push({ name: 'All routes tier-explicit', pass: routes.every(r => r.tier) });

    const passed = checks.filter(c => c.pass).length;
    const score = checks.length > 0 ? Math.round((passed / checks.length) * 100) : 0;

    const scoreColor = score === 100 ? 'text-green-400' : score >= 60 ? 'text-amber-400' : 'text-red-400';
    const scoreBg = score === 100 ? 'border-green-800 bg-green-950/30' : score >= 60 ? 'border-amber-800 bg-amber-950/30' : 'border-red-800 bg-red-950/30';

    let html = '';

    // Score card
    html += `<div class="border ${scoreBg} rounded-lg p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-semibold text-gray-200">Compliance Score</span>
            <span class="text-2xl font-bold ${scoreColor}">${score}%</span>
        </div>
        <div class="w-full bg-gray-800 rounded-full h-2 mb-3">
            <div class="h-2 rounded-full transition-all duration-500 ${score === 100 ? 'bg-green-500' : score >= 60 ? 'bg-amber-500' : 'bg-red-500'}" style="width: ${score}%"></div>
        </div>
        <div class="space-y-1">
            ${checks.map(c => `<div class="flex items-center gap-2 text-xs">
                <span class="${c.pass ? 'text-green-400' : 'text-red-400'}">${c.pass ? '&#10003;' : '&#10007;'}</span>
                <span class="${c.pass ? 'text-gray-400' : 'text-red-300'}">${c.name}</span>
            </div>`).join('')}
        </div>
    </div>`;

    // Guard status cards
    if (guards.length > 0) {
        html += `<div>
            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Guards (${totalGuards})</h3>
            <div class="grid gap-2">
                ${guards.map(g => {
                    const mode = g.fail_mode === 'closed' ? 'CLOSED' : 'OPEN';
                    const modeColor = g.fail_mode === 'closed' ? 'bg-green-900/50 text-green-400 border-green-800' : 'bg-red-900/50 text-red-400 border-red-800';
                    const tierBadge = `<span class="px-1.5 py-0.5 rounded text-xs bg-blue-900/50 text-blue-400 border border-blue-800">${g.tier}</span>`;
                    const triggerInfo = g.trigger ? `<span class="text-xs text-amber-400">on: ${g.trigger.on || 'event'}</span>` : '';
                    const actionInfo = g.action ? `<span class="text-xs text-purple-400">dispatch: ${g.action.dispatch || 'action'}</span>` : '';
                    const ceilingInfo = g.ceiling !== undefined ? `<span class="text-xs text-cyan-400">ceiling: ${g.ceiling}</span>` : '';
                    return `<div class="border border-gray-800 rounded p-3 bg-gray-900/30">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-sm font-medium text-gray-200">${g.name}</span>
                            <div class="flex gap-1.5">${tierBadge} <span class="px-1.5 py-0.5 rounded text-xs border ${modeColor}">${mode}</span></div>
                        </div>
                        ${g.input_type ? `<div class="text-xs text-gray-500">${g.input_type} &rarr; ${g.output_type || '?'}</div>` : ''}
                        <div class="flex gap-3 mt-1">${triggerInfo}${actionInfo}${ceilingInfo}</div>
                    </div>`;
                }).join('')}
            </div>
        </div>`;
    }

    // Route security matrix
    if (routes.length > 0) {
        html += `<div>
            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Route Security (${totalRoutes})</h3>
            <div class="border border-gray-800 rounded overflow-hidden">
                <table class="w-full text-xs">
                    <thead><tr class="bg-gray-900/50 text-gray-500">
                        <th class="text-left px-3 py-1.5">Method</th>
                        <th class="text-left px-3 py-1.5">Path</th>
                        <th class="text-left px-3 py-1.5">Tier</th>
                        <th class="text-left px-3 py-1.5">Auth</th>
                        <th class="text-left px-3 py-1.5">Rate Limit</th>
                    </tr></thead>
                    <tbody>
                        ${routes.map(r => {
                            const isMut = ['POST','PUT','DELETE','PATCH'].includes(r.method);
                            const methodColor = isMut ? 'text-amber-400' : 'text-green-400';
                            const authStatus = r.auth && r.auth !== 'none'
                                ? `<span class="text-green-400">&#10003; ${r.auth}</span>`
                                : (isMut ? '<span class="text-red-400">&#10007; missing</span>' : '<span class="text-gray-600">none</span>');
                            const rl = r.rate_limit ? `<span class="text-cyan-400">${r.rate_limit}</span>` : '<span class="text-gray-600">-</span>';
                            return `<tr class="border-t border-gray-800/50">
                                <td class="px-3 py-1.5 ${methodColor} font-medium">${r.method}</td>
                                <td class="px-3 py-1.5 text-gray-300">${r.path}</td>
                                <td class="px-3 py-1.5 text-blue-400">${r.tier}</td>
                                <td class="px-3 py-1.5">${authStatus}</td>
                                <td class="px-3 py-1.5">${rl}</td>
                            </tr>`;
                        }).join('')}
                    </tbody>
                </table>
            </div>
        </div>`;
    }

    // Summary counters
    html += `<div class="grid grid-cols-4 gap-2">
        ${[
            { label: 'Guards', value: totalGuards, color: 'text-green-400' },
            { label: 'Routes', value: totalRoutes, color: 'text-blue-400' },
            { label: 'Services', value: services.length, color: 'text-purple-400' },
            { label: 'DTOs', value: dtos.length, color: 'text-amber-400' },
        ].map(s => `<div class="border border-gray-800 rounded p-3 text-center bg-gray-900/30">
            <div class="text-xl font-bold ${s.color}">${s.value}</div>
            <div class="text-xs text-gray-500">${s.label}</div>
        </div>`).join('')}
    </div>`;

    // Reactive guard telemetry placeholder
    if (reactive > 0) {
        html += `<div class="border border-gray-800 rounded p-3 bg-gray-900/30">
            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Reactive Guard Telemetry</h3>
            <p class="text-xs text-gray-500 mb-2">Connect to <code class="text-blue-400">gate-probe-summary.php</code> for live data.</p>
            <div class="space-y-2">
                ${guards.filter(g => g.trigger).map(g => `<div class="flex items-center justify-between">
                    <span class="text-xs text-gray-300">${g.name}</span>
                    <div class="flex gap-2 text-xs">
                        <span class="px-1.5 py-0.5 rounded bg-gray-800 text-gray-400">triggered: <span class="text-amber-400">-</span></span>
                        <span class="px-1.5 py-0.5 rounded bg-gray-800 text-gray-400">dispatched: <span class="text-purple-400">-</span></span>
                        <span class="px-1.5 py-0.5 rounded bg-gray-800 text-gray-400">avg_ms: <span class="text-cyan-400">-</span></span>
                    </div>
                </div>`).join('')}
            </div>
            <p class="text-xs text-gray-600 mt-2 italic">Live telemetry requires the Evolution runtime gate-probe endpoint.</p>
        </div>`;
    }

    dashboardContent.innerHTML = html;
}

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
