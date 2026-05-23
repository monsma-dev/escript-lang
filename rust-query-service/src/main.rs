use actix_web::{web, App, HttpServer, HttpResponse, Responder};
use serde::{Deserialize, Serialize};
use serde_json::Value;
use sqlx::{Pool, MySql, MySqlPool, postgres::PgPool, sqlite::SqlitePool};
use std::collections::HashMap;
use std::fs;
use anyhow::Result;
use sha2::{Sha256, Digest};
use hex;

#[derive(Debug, Deserialize)]
struct QueryRequest {
    query_id: String,
    params: HashMap<String, Value>,
    security_level: String,
    timeout_ms: u64,
    requires_transaction: bool,
}

#[derive(Debug, Serialize)]
struct QueryResponse {
    success: bool,
    data: Option<Value>,
    error: Option<String>,
    rows_affected: Option<u64>,
}

#[derive(Debug, Deserialize)]
struct QueryConfig {
    queries: HashMap<String, QueryDefinition>,
    security: SecurityConfig,
}

#[derive(Debug, Deserialize)]
struct QueryDefinition {
    id: String,
    description: String,
    security_level: String,
    parameters: HashMap<String, ParameterDefinition>,
    query_template: QueryTemplate,
    fail_closed: bool,
    timeout_ms: u64,
    requires_transaction: Option<bool>,
}

#[derive(Debug, Deserialize)]
struct ParameterDefinition {
    #[serde(rename = "type")]
    param_type: String,
    required: bool,
    #[serde(default)]
    default: Option<Value>,
    #[serde(default)]
    min: Option<f64>,
    #[serde(default)]
    max: Option<f64>,
    #[serde(default)]
    pattern: Option<String>,
}

#[derive(Debug, Deserialize)]
struct QueryTemplate {
    sql: String,
    hash: String,
}

#[derive(Debug, Deserialize)]
struct SecurityConfig {
    default_fail_closed: bool,
    allowed_security_levels: Vec<String>,
    max_timeout_ms: u64,
    query_hash_algorithm: String,
}

struct AppState {
    query_config: QueryConfig,
    db_pool: Option<MySqlPool>, // Can be extended for other DB types
}

async fn execute_query(
    req: web::Json<QueryRequest>,
    state: web::Data<AppState>,
) -> impl Responder {
    let query_id = &req.query_id;
    
    // Check if query exists in config
    let query_def = match state.query_config.queries.get(query_id) {
        Some(def) => def,
        None => {
            return HttpResponse::BadRequest().json(QueryResponse {
                success: false,
                data: None,
                error: Some(format!("Query not whitelisted: {}", query_id)),
                rows_affected: None,
            });
        }
    };
    
    // Verify query hash for integrity
    let sql = &query_def.query_template.sql;
    let expected_hash = &query_def.query_template.hash;
    let actual_hash = format!("sha256:{}", hex::encode(compute_sha256(sql)));
    
    if expected_hash != &actual_hash {
        return HttpResponse::InternalServerError().json(QueryResponse {
            success: false,
            data: None,
            error: Some("Query hash mismatch - possible tampering".to_string()),
            rows_affected: None,
        });
    }
    
    // Execute query (placeholder - would use actual DB pool)
    // For now, return a mock response
    HttpResponse::Ok().json(QueryResponse {
        success: true,
        data: Some(serde_json::json!({
            "query_id": query_id,
            "params": req.params,
            "sql": sql
        })),
        error: None,
        rows_affected: Some(0),
    })
}

fn compute_sha256(input: &str) -> Vec<u8> {
    let mut hasher = Sha256::new();
    hasher.update(input.as_bytes());
    hasher.finalize().to_vec()
}

async fn health_check() -> impl Responder {
    HttpResponse::Ok().json(serde_json::json!({
        "status": "healthy",
        "service": "escript-query-service",
        "version": "1.0.0"
    }))
}

#[actix_web::main]
async fn main() -> Result<()> {
    // Load query configuration
    let config_path = "../config/db_queries.json";
    let config_content = fs::read_to_string(config_path)
        .expect("Failed to read db_queries.json");
    
    let query_config: QueryConfig = serde_json::from_str(&config_content)
        .expect("Failed to parse db_queries.json");
    
    tracing_subscriber::fmt::init();
    
    let app_state = web::Data::new(AppState {
        query_config,
        db_pool: None, // Would initialize actual DB pool here
    });
    
    println!("EScript Query Service starting on http://127.0.0.1:8080");
    
    HttpServer::new(move || {
        App::new()
            .app_data(app_state.clone())
            .route("/query", web::post().to(execute_query))
            .route("/health", web::get().to(health_check))
    })
    .bind("127.0.0.1:8080")?
    .run()
    .await?;
    
    Ok(())
}
