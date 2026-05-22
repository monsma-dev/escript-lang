/**
 * EScript Browser Compiler
 * JavaScript port of the PHP reference compiler.
 * Lexer → Parser → Validator → IR Emitter — all client-side.
 */

// ─── Token Types ─────────────────────────────────────────────────────────────

const T = {
    MODULE: 'MODULE', SERVICE: 'SERVICE', ROUTE: 'ROUTE', DTO: 'DTO',
    GUARD: 'GUARD', ISLAND: 'ISLAND', FN: 'FN', INJECT: 'INJECT',
    PUB: 'PUB', PRIVATE: 'PRIVATE', PROTECTED: 'PROTECTED',
    EXTENDS: 'EXTENDS', IMPLEMENTS: 'IMPLEMENTS', THROWS: 'THROWS',
    RETURN: 'RETURN', LET: 'LET', IF: 'IF', ELSE: 'ELSE', READONLY: 'READONLY',
    STRING: 'STRING', INTEGER: 'INTEGER', FLOAT: 'FLOAT',
    TRUE: 'TRUE', FALSE: 'FALSE', NULL: 'NULL',
    IDENT: 'IDENT',
    GET: 'GET', POST: 'POST', PUT: 'PUT', PATCH: 'PATCH', DELETE: 'DELETE', HEAD: 'HEAD',
    TIER_PHP: 'TIER_PHP', TIER_RUST: 'TIER_RUST', TIER_ELIXIR: 'TIER_ELIXIR', TIER_NODE: 'TIER_NODE',
    AT: 'AT', COLON: 'COLON', SEMICOLON: 'SEMICOLON', COMMA: 'COMMA', DOT: 'DOT',
    ARROW: 'ARROW', EQUALS: 'EQUALS', QUESTION: 'QUESTION',
    BACKSLASH: 'BACKSLASH', SLASH: 'SLASH',
    LBRACE: 'LBRACE', RBRACE: 'RBRACE', LPAREN: 'LPAREN', RPAREN: 'RPAREN',
    LBRACKET: 'LBRACKET', RBRACKET: 'RBRACKET', LT: 'LT', GT: 'GT',
    EOF: 'EOF',
};

const KEYWORDS = {
    module: T.MODULE, service: T.SERVICE, route: T.ROUTE, dto: T.DTO,
    guard: T.GUARD, island: T.ISLAND, fn: T.FN, inject: T.INJECT,
    pub: T.PUB, private: T.PRIVATE, protected: T.PROTECTED,
    extends: T.EXTENDS, implements: T.IMPLEMENTS, throws: T.THROWS,
    return: T.RETURN, let: T.LET, if: T.IF, else: T.ELSE, readonly: T.READONLY,
    true: T.TRUE, false: T.FALSE, null: T.NULL,
    GET: T.GET, POST: T.POST, PUT: T.PUT, PATCH: T.PATCH, DELETE: T.DELETE, HEAD: T.HEAD,
};

const SINGLE = {
    ':': T.COLON, ';': T.SEMICOLON, ',': T.COMMA, '.': T.DOT,
    '=': T.EQUALS, '?': T.QUESTION, '\\': T.BACKSLASH, '/': T.SLASH,
    '{': T.LBRACE, '}': T.RBRACE, '(': T.LPAREN, ')': T.RPAREN,
    '[': T.LBRACKET, ']': T.RBRACKET, '<': T.LT, '>': T.GT,
};

// ─── Lexer ───────────────────────────────────────────────────────────────────

function tokenize(source) {
    const tokens = [];
    let pos = 0, line = 1, col = 1;
    const len = source.length;

    function advance(n = 1) { for (let i = 0; i < n; i++) { pos++; col++; } }
    function peek(off = 0) { return pos + off < len ? source[pos + off] : null; }
    function isAlpha(c) { return c && /[a-zA-Z_]/.test(c); }
    function isAlnum(c) { return c && /[a-zA-Z0-9_]/.test(c); }
    function isDigit(c) { return c && /[0-9]/.test(c); }

    function skipWS() {
        while (pos < len) {
            const ch = source[pos];
            if (ch === ' ' || ch === '\t' || ch === '\r') { advance(); continue; }
            if (ch === '\n') { pos++; line++; col = 1; continue; }
            if (ch === '/' && peek(1) === '/') {
                while (pos < len && source[pos] !== '\n') pos++;
                continue;
            }
            if (ch === '/' && peek(1) === '*') {
                advance(2);
                while (pos < len) {
                    if (source[pos] === '*' && peek(1) === '/') { advance(2); break; }
                    if (source[pos] === '\n') { line++; col = 0; }
                    advance();
                }
                continue;
            }
            break;
        }
    }

    while (pos < len) {
        skipWS();
        if (pos >= len) break;
        const ch = source[pos];
        const sl = line, sc = col;

        // String
        if (ch === '"') {
            advance();
            let val = '';
            while (pos < len && source[pos] !== '"') {
                if (source[pos] === '\\' && pos + 1 < len) {
                    advance();
                    val += ({ n: '\n', t: '\t', '\\': '\\', '"': '"' }[source[pos]] || '\\' + source[pos]);
                } else {
                    val += source[pos];
                }
                advance();
            }
            if (pos < len) advance();
            tokens.push({ type: T.STRING, value: val, line: sl, col: sc });
            continue;
        }

        // Number
        if (isDigit(ch)) {
            let val = '', isF = false;
            while (pos < len && (isDigit(source[pos]) || source[pos] === '.')) {
                if (source[pos] === '.') { if (isF) break; isF = true; }
                val += source[pos]; advance();
            }
            tokens.push({ type: isF ? T.FLOAT : T.INTEGER, value: val, line: sl, col: sc });
            continue;
        }

        // Identifier / keyword
        if (isAlpha(ch)) {
            let val = '';
            while (pos < len && isAlnum(source[pos])) { val += source[pos]; advance(); }
            tokens.push({ type: KEYWORDS[val] || T.IDENT, value: val, line: sl, col: sc });
            continue;
        }

        // @ token
        if (ch === '@') {
            advance();
            let word = '';
            while (pos < len && isAlnum(source[pos])) { word += source[pos]; advance(); }
            const tiers = { php: T.TIER_PHP, rust: T.TIER_RUST, elixir: T.TIER_ELIXIR, node: T.TIER_NODE };
            if (tiers[word]) {
                tokens.push({ type: tiers[word], value: '@' + word, line: sl, col: sc });
            } else {
                tokens.push({ type: T.AT, value: '@' + word, line: sl, col: sc });
            }
            continue;
        }

        // Arrow
        if (ch === '-' && peek(1) === '>') {
            advance(2);
            tokens.push({ type: T.ARROW, value: '->', line: sl, col: sc });
            continue;
        }

        // Single char
        if (SINGLE[ch]) {
            advance();
            tokens.push({ type: SINGLE[ch], value: ch, line: sl, col: sc });
            continue;
        }

        throw new Error(`Unexpected character '${ch}' at line ${sl}, column ${sc}`);
    }

    tokens.push({ type: T.EOF, value: '', line, col });
    return tokens;
}

// ─── Parser ──────────────────────────────────────────────────────────────────

function parse(tokens) {
    let pos = 0;
    const cur = () => tokens[pos] || { type: T.EOF, value: '', line: 0, col: 0 };
    const pk = () => tokens[pos + 1] || { type: T.EOF, value: '', line: 0, col: 0 };
    const adv = () => pos++;
    const consume = (type) => {
        const t = cur();
        if (t.type !== type) throw new Error(`[${t.line}:${t.col}] Expected ${type}, got ${t.type}(${t.value})`);
        adv(); return t;
    };
    const consumeIdent = () => {
        const t = cur();
        const kwTypes = [T.IDENT, T.DTO, T.GUARD, T.MODULE, T.SERVICE, T.ROUTE, T.ISLAND, T.INJECT, T.FN];
        if (kwTypes.includes(t.type)) { adv(); return { ...t, type: T.IDENT }; }
        throw new Error(`[${t.line}:${t.col}] Expected identifier, got ${t.type}(${t.value})`);
    };

    function parseAnnotations() {
        const anns = [];
        while (cur().type === T.AT) {
            const tok = consume(T.AT);
            const name = tok.value.slice(1);
            let args = {};
            if (cur().type === T.LPAREN) {
                consume(T.LPAREN);
                while (cur().type !== T.RPAREN) {
                    if (cur().type === T.IDENT && (pk().type === T.COLON || pk().type === T.EQUALS)) {
                        const key = consume(T.IDENT).value; adv();
                        args[key] = parseLiteral();
                    } else {
                        const v = parseLiteral();
                        args[Object.keys(args).length] = v;
                    }
                    if (cur().type === T.COMMA) adv();
                }
                consume(T.RPAREN);
            }
            anns.push({ name, args });
        }
        return anns;
    }

    function parseLiteral() {
        const t = cur(); adv();
        if (t.type === T.STRING) return t.value;
        if (t.type === T.INTEGER) return parseInt(t.value);
        if (t.type === T.FLOAT) return parseFloat(t.value);
        if (t.type === T.TRUE) return true;
        if (t.type === T.FALSE) return false;
        if (t.type === T.NULL) return null;
        if (t.type === T.IDENT) return t.value;
        throw new Error(`[${t.line}:${t.col}] Expected literal, got ${t.type}`);
    }

    function parseNumeric() {
        const t = cur(); adv();
        if (t.type === T.INTEGER) return parseInt(t.value);
        if (t.type === T.FLOAT) return parseFloat(t.value);
        throw new Error(`[${t.line}:${t.col}] Expected number`);
    }

    function parseFqcn() {
        let name = consume(T.IDENT).value;
        while (cur().type === T.BACKSLASH) { adv(); name += '\\' + consume(T.IDENT).value; }
        while (cur().type === T.COLON && pk().type === T.COLON) { adv(); adv(); name += '::' + consume(T.IDENT).value; }
        return name;
    }

    function parseFqcnList() {
        const list = [parseFqcn()];
        while (cur().type === T.COMMA) { adv(); list.push(parseFqcn()); }
        return list;
    }

    function parseIdentArray() {
        consume(T.LBRACKET);
        const items = [];
        while (cur().type !== T.RBRACKET) {
            let item = consume(T.IDENT).value;
            if (cur().type === T.COLON) { adv(); item += ':' + consume(T.IDENT).value; }
            items.push(item);
            if (cur().type === T.COMMA) adv();
        }
        consume(T.RBRACKET);
        return items;
    }

    function parseTypeExpr() {
        if (cur().type === T.IDENT && cur().value === 'Result') {
            adv(); consume(T.LT); const ok = parseTypeExpr(); consume(T.COMMA); const err = parseTypeExpr(); consume(T.GT);
            return `Result<${ok}, ${err}>`;
        }
        let base = parseFqcn();
        if (cur().type === T.QUESTION) { adv(); return base + '?'; }
        if (cur().type === T.LBRACKET && pk().type === T.RBRACKET) { adv(); adv(); return base + '[]'; }
        return base;
    }

    function parseTier() {
        const t = cur();
        if ([T.TIER_PHP, T.TIER_RUST, T.TIER_ELIXIR, T.TIER_NODE].includes(t.type)) {
            adv(); return t.value.replace('@', '');
        }
        throw new Error(`[${t.line}:${t.col}] Expected tier prefix`);
    }

    function skipBlock() {
        consume(T.LBRACE); let d = 1;
        while (d > 0 && cur().type !== T.EOF) {
            if (cur().type === T.LBRACE) d++;
            if (cur().type === T.RBRACE) d--;
            if (d > 0) adv();
        }
        consume(T.RBRACE);
    }

    function parseDto(anns) {
        const line = cur().line; consume(T.DTO); const name = consume(T.IDENT).value;
        let ext = null;
        if (cur().type === T.EXTENDS) { adv(); ext = parseFqcn(); }
        consume(T.LBRACE);
        const fields = [];
        while (cur().type !== T.RBRACE) {
            const fa = parseAnnotations();
            const fn = consume(T.IDENT).value; consume(T.COLON);
            const ft = parseTypeExpr();
            let def = null;
            if (cur().type === T.EQUALS) { adv(); def = parseLiteral(); }
            consume(T.SEMICOLON);
            fields.push({ name: fn, type: ft, default: def, annotations: fa });
        }
        consume(T.RBRACE);
        return { kind: 'dto', name, extends: ext, fields, annotations: anns, line };
    }

    function parseGuard(anns) {
        const line = cur().line; consume(T.GUARD); const name = consume(T.IDENT).value;
        consume(T.LBRACE);
        const data = { name, annotations: anns };
        while (cur().type !== T.RBRACE) {
            const key = consume(T.IDENT).value; consume(T.COLON);
            if (key === 'tier') data.tier = parseTier();
            else if (key === 'fail_mode') data.fail_mode = consume(T.IDENT).value;
            else if (key === 'input' || key === 'output') data[key] = consume(T.IDENT).value;
            else if (key === 'ceiling') data.ceiling = parseNumeric();
            else data[key] = parseLiteral();
            consume(T.SEMICOLON);
        }
        consume(T.RBRACE);
        return { kind: 'guard', ...data, line };
    }

    function parseRoute(anns) {
        const line = cur().line; consume(T.ROUTE);
        const method = cur().value; adv();
        const path = consume(T.STRING).value; consume(T.ARROW);
        const tier = parseTier();
        const fqcn = parseFqcn();
        let action = null;
        if (cur().type === T.AT) {
            const at = consume(T.AT); action = at.value.slice(1);
            if (!action) action = consume(T.IDENT).value;
        }
        let options = {};
        if (cur().type === T.LBRACE) {
            consume(T.LBRACE);
            while (cur().type !== T.RBRACE) {
                const key = consumeIdent().value; consume(T.COLON);
                if (key === 'middleware' || key === 'rust_middlewares') options[key] = parseIdentArray();
                else if (key === 'auth' || key === 'rate_limit' || key === 'dto') options[key] = consumeIdent().value;
                else options[key] = parseLiteral();
                consume(T.SEMICOLON);
            }
            consume(T.RBRACE);
        }
        consume(T.SEMICOLON);
        return { kind: 'route', method, path, tier, target: { controller: fqcn, action }, options, annotations: anns, line };
    }

    function parseMethod(anns) {
        let vis = 'public';
        if (cur().type === T.PUB) { vis = 'public'; adv(); }
        else if (cur().type === T.PRIVATE) { vis = 'private'; adv(); }
        else if (cur().type === T.PROTECTED) { vis = 'protected'; adv(); }
        consume(T.FN); const name = consume(T.IDENT).value;
        consume(T.LPAREN);
        const params = [];
        while (cur().type !== T.RPAREN) {
            const pn = consume(T.IDENT).value; consume(T.COLON); const pt = parseTypeExpr();
            let pd = null; if (cur().type === T.EQUALS) { adv(); pd = parseLiteral(); }
            params.push({ name: pn, type: pt, default: pd });
            if (cur().type === T.COMMA) adv();
        }
        consume(T.RPAREN);
        let ret = null; if (cur().type === T.ARROW) { adv(); ret = parseTypeExpr(); }
        let throws = []; if (cur().type === T.THROWS) { adv(); throws.push(parseTypeExpr()); }
        if (cur().type === T.LBRACE) skipBlock(); else consume(T.SEMICOLON);
        return { name, visibility: vis, params, return_type: ret, throws, annotations: anns };
    }

    function parseService(anns) {
        const line = cur().line; consume(T.SERVICE); const name = consume(T.IDENT).value;
        let ext = null; if (cur().type === T.EXTENDS) { adv(); ext = parseFqcn(); }
        let impls = []; if (cur().type === T.IMPLEMENTS) { adv(); impls = parseFqcnList(); }
        consume(T.LBRACE);
        const injects = [], guards = [], methods = [];
        while (cur().type !== T.RBRACE) {
            const ma = parseAnnotations();
            if (cur().type === T.INJECT) {
                adv(); const in_ = consume(T.IDENT).value; consume(T.COLON); const it = parseTypeExpr(); consume(T.SEMICOLON);
                injects.push({ name: in_, type: it });
            } else if (cur().type === T.GUARD) {
                adv(); guards.push(consume(T.IDENT).value); consume(T.SEMICOLON);
            } else if ([T.PUB, T.PRIVATE, T.PROTECTED, T.FN].includes(cur().type)) {
                methods.push(parseMethod(ma));
            } else {
                throw new Error(`[${cur().line}:${cur().col}] Unexpected in service: ${cur().type}`);
            }
        }
        consume(T.RBRACE);
        return { kind: 'service', name, extends: ext, implements: impls, injects, guards, methods, annotations: anns, line };
    }

    function parseModule() {
        const line = cur().line; consume(T.MODULE);
        let name = consume(T.IDENT).value;
        while (cur().type === T.SLASH) { adv(); name += '/' + consume(T.IDENT).value; }
        consume(T.LBRACE);
        const data = { name };
        while (cur().type !== T.RBRACE) {
            const key = consume(T.IDENT).value; consume(T.COLON);
            if (key === 'type' || key === 'surface') data[key] = consume(T.IDENT).value;
            else if (key === 'priority') data[key] = parseInt(consume(T.INTEGER).value);
            else if (key === 'requires' || key === 'middleware') data[key] = parseIdentArray();
            else if (key === 'version' || key === 'bootstrapper' || key === 'license') {
                data[key] = cur().type === T.STRING ? consume(T.STRING).value : parseFqcn();
            }
            else data[key] = parseLiteral();
            consume(T.SEMICOLON);
        }
        consume(T.RBRACE);
        return { kind: 'module', ...data, line };
    }

    function parseIsland() {
        const line = cur().line; consume(T.ISLAND); const name = consume(T.IDENT).value;
        consume(T.LBRACE);
        const data = { name };
        while (cur().type !== T.RBRACE) {
            const key = consume(T.IDENT).value; consume(T.COLON);
            if (key === 'dto' || key === 'lane') data[key] = consume(T.IDENT).value;
            else if (key === 'component' || key === 'wasm' || key === 'fallback') data[key] = consume(T.STRING).value;
            else data[key] = parseLiteral();
            consume(T.SEMICOLON);
        }
        consume(T.RBRACE);
        return { kind: 'island', ...data, line };
    }

    const nodes = [];
    while (cur().type !== T.EOF) {
        const anns = parseAnnotations();
        const t = cur();
        if (t.type === T.MODULE) nodes.push(parseModule());
        else if (t.type === T.SERVICE) nodes.push(parseService(anns));
        else if (t.type === T.ROUTE) nodes.push(parseRoute(anns));
        else if (t.type === T.DTO) nodes.push(parseDto(anns));
        else if (t.type === T.GUARD) nodes.push(parseGuard(anns));
        else if (t.type === T.ISLAND) nodes.push(parseIsland());
        else throw new Error(`[${t.line}:${t.col}] Expected declaration, got ${t.type}(${t.value})`);
    }
    return nodes;
}

// ─── Validator ───────────────────────────────────────────────────────────────

function validate(nodes) {
    const errors = [];
    const guardNames = nodes.filter(n => n.kind === 'guard').map(n => n.name);

    for (const node of nodes) {
        if (node.kind === 'route') {
            const muts = ['POST', 'PUT', 'DELETE', 'PATCH'];
            if (muts.includes(node.method)) {
                const hasAuth = (node.annotations || []).some(a => a.name === 'auth') || node.options?.auth;
                if (!hasAuth) errors.push(`Line ${node.line}: ${node.method} '${node.path}' requires @auth`);
            }
        }
        if (node.kind === 'guard') {
            if (node.fail_mode === 'open') {
                const hasUnsafe = (node.annotations || []).some(a => a.name === 'unsafe');
                if (!hasUnsafe) errors.push(`Line ${node.line}: Guard '${node.name}' fail_mode 'open' requires @unsafe`);
            }
        }
        if (node.kind === 'service') {
            for (const g of node.guards || []) {
                if (!guardNames.includes(g)) errors.push(`Line ${node.line}: Undefined guard '${g}'`);
            }
        }
    }
    return errors;
}

// ─── IR Emitter ──────────────────────────────────────────────────────────────

function emitIR(nodes, source) {
    const ir = { version: '1.0.0', source, compiled_at: new Date().toISOString() };
    const sections = { modules: [], routes: [], services: [], dtos: [], guards: [], islands: [] };

    for (const n of nodes) {
        if (n.kind === 'dto') {
            sections.dtos.push({
                name: n.name,
                fields: n.fields.map(f => ({
                    name: f.name, type: f.type.replace(/\?$/, ''),
                    nullable: f.type.endsWith('?'),
                    ...(f.default !== null ? { default: f.default } : {}),
                })),
            });
        } else if (n.kind === 'guard') {
            const g = { name: n.name, tier: n.tier || 'rust', fail_mode: n.fail_mode || 'closed' };
            if (n.input) g.input_type = n.input;
            if (n.output) g.output_type = n.output;
            if (n.ceiling !== undefined) g.ceiling = n.ceiling;
            for (const a of n.annotations || []) {
                if (a.name === 'trigger') g.trigger = a.args;
                if (a.name === 'action') g.action = a.args;
                if (a.name === 'condition') g.conditions = a.args;
                if (a.name === 'unsafe') g.unsafe_acknowledged = true;
            }
            sections.guards.push(g);
        } else if (n.kind === 'route') {
            const r = { method: n.method, path: n.path, tier: n.tier, target: {} };
            if (n.tier === 'rust') r.target.action = n.target.controller;
            else { r.target.controller = n.target.controller; if (n.target.action) r.target.action = n.target.action; }
            if (n.options?.middleware) r.middleware = n.options.middleware;
            if (n.options?.dto) r.dto = n.options.dto;
            const anMap = {};
            for (const a of n.annotations || []) anMap[a.name] = a.args;
            if (anMap.auth) r.auth = Object.values(anMap.auth)[0];
            else if (n.options?.auth) r.auth = n.options.auth;
            if (anMap.rate_limit) r.rate_limit = Object.values(anMap.rate_limit)[0];
            else if (n.options?.rate_limit) r.rate_limit = n.options.rate_limit;
            r.annotations = { fail_closed: true };
            sections.routes.push(r);
        } else if (n.kind === 'service') {
            const s = { name: n.name, tier: 'php', fail_mode: 'closed' };
            for (const a of n.annotations || []) {
                if (a.name === 'tier') s.tier = Object.values(a.args)[0];
                if (a.name === 'fail_closed') s.fail_mode = 'closed';
            }
            if (n.implements?.length) s.implements = n.implements;
            if (n.injects?.length) s.injects = n.injects;
            if (n.guards?.length) s.guards = n.guards;
            if (n.methods?.length) {
                s.methods = n.methods.map(m => {
                    const r = { name: m.name, visibility: m.visibility };
                    if (m.params?.length) r.params = m.params.map(p => {
                        const o = { name: p.name, type: p.type };
                        if (p.default !== null) o.default = p.default;
                        return o;
                    });
                    if (m.return_type) r.return_type = emitTypeRef(m.return_type);
                    if (m.throws?.length) r.throws = m.throws;
                    return r;
                });
            }
            sections.services.push(s);
        } else if (n.kind === 'island') {
            const i = { name: n.name };
            if (n.dto) i.dto = n.dto;
            if (n.component) i.component = n.component;
            if (n.wasm) i.wasm = n.wasm;
            if (n.fallback) i.fallback = n.fallback;
            if (n.lane) i.lane = n.lane;
            sections.islands.push(i);
        }
    }

    for (const [k, v] of Object.entries(sections)) { if (v.length) ir[k] = v; }
    return ir;
}

function emitTypeRef(type) {
    const rm = type.match(/^Result<(.+),\s*(.+)>$/);
    if (rm) return { kind: 'result', ok: rm[1].trim(), err: rm[2].trim() };
    if (type.endsWith('?')) return { kind: 'nullable', type: type.slice(0, -1) };
    if (type.endsWith('[]')) return { kind: 'array', type: type.slice(0, -2) };
    return { kind: 'simple', type };
}

// ─── Public API ──────────────────────────────────────────────────────────────

window.EScriptCompiler = {
    tokenize,
    parse,
    validate,
    emitIR,
    compile(source, filename = '<playground>') {
        const start = performance.now();
        const tokens = tokenize(source);
        const ast = parse(tokens);
        const errors = validate(ast);
        const ir = errors.length === 0 ? emitIR(ast, filename) : null;
        const elapsed = (performance.now() - start).toFixed(1);
        return { tokens, ast, errors, ir, elapsed };
    },
};
