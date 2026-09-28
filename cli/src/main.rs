use clap::Parser;
use serde::Deserialize;
use std::fs::File;
use std::io::{self, Read, Write};
use std::path::{Path, PathBuf};

#[derive(Debug, Deserialize, Default)]
struct FileConfig {
    server: Option<String>,
    servidor: Option<String>,
    token: Option<String>,
    ficha: Option<String>,
    dialect: Option<String>,
    dialeto: Option<String>,
    sotaque: Option<String>,
    expires: Option<String>,
    caduca: Option<String>,
    oneshot: Option<bool>,
    queima: Option<bool>,
    secret: Option<bool>,
    segredo: Option<bool>,
}

#[derive(Parser, Debug)]
#[command(
    name = "fux",
    about = "fux - A malícia popular do boca a boca no terminal. fux é um chuchuzim fuxiquento.",
    long_about = None
)]
struct Cli {
    /// Target file to upload (or omit when reading from stdin, shortening URL or managing)
    #[arg(value_name = "FILE")]
    file: Option<PathBuf>,

    /// Server URL
    #[arg(
        short = 'S',
        long = "server",
        visible_alias = "servidor",
        env = "FUXICO_URL"
    )]
    server: Option<String>,

    /// Authorization token
    #[arg(
        short = 't',
        long = "token",
        visible_alias = "ficha",
        env = "FUXICO_TOKEN"
    )]
    token: Option<String>,

    /// Protocol dialect ('nullpointer' / '0x0' or 'rustypaste' / 'rpaste')
    #[arg(
        short = 'D',
        visible_short_alias = 'm',
        long = "dialect",
        visible_alias = "dialeto",
        alias = "sotaque"
    )]
    dialect: Option<String>,

    /// Set expiration (e.g. "2h", "24", "7d", "1y")
    #[arg(
        short = 'e',
        long = "expires",
        visible_alias = "caduca"
    )]
    expires: Option<String>,

    /// Burn after reading (delete after single access)
    #[arg(
        short = 'q',
        visible_short_alias = 'o',
        long = "oneshot",
        visible_alias = "queima",
        alias = "1"
    )]
    oneshot: bool,

    /// Generate an unguessable 16-character secret slug
    #[arg(
        short = 's',
        long = "secret",
        visible_alias = "segredo"
    )]
    secret: bool,

    /// Custom slug / alias filename
    #[arg(
        short = 'n',
        long = "name",
        visible_alias = "nome",
        alias = "alias",
        alias = "apelido"
    )]
    name: Option<String>,

    /// Shorten a destination URL instead of uploading a file
    #[arg(
        short = 'u',
        long = "url",
        visible_alias = "encurta",
        alias = "shorten"
    )]
    url: Option<String>,

    /// Delete an item by slug
    #[arg(
        short = 'd',
        long = "delete",
        visible_alias = "apaga"
    )]
    delete: Option<String>,

    /// List active files in JSON
    #[arg(
        short = 'l',
        long = "list",
        visible_alias = "lista"
    )]
    list: bool,

    /// Copy returned URL to clipboard (xclip, wl-copy, pbcopy)
    #[arg(
        short = 'c',
        long = "copy",
        visible_alias = "copia"
    )]
    copy: bool,

    /// Initialize an ephemeral Git repository
    #[arg(
        long = "git-init",
        visible_alias = "git",
        value_name = "NAME"
    )]
    git_init: Option<String>,

    /// Custom path to config TOML file
    #[arg(
        short = 'C',
        long = "config",
        visible_alias = "conf"
    )]
    config: Option<PathBuf>,

    /// Quiet mode (suppress stderr messages, URL only on stdout)
    #[arg(
        long = "quiet",
        visible_alias = "calado",
        alias = "caladinho"
    )]
    quiet: bool,

    /// Upload multiple files as a Gist
    #[arg(
        short = 'g',
        long = "gist",
        value_name = "FILES",
        num_args = 1..,
        help = "Upload multiple files as a Gist"
    )]
    gist: Vec<PathBuf>,
}

fn load_file_config(cli_config: Option<&Path>) -> FileConfig {
    if let Some(path) = cli_config {
        if let Ok(content) = std::fs::read_to_string(path) {
            return toml::from_str(&content).unwrap_or_default();
        }
        return FileConfig::default();
    }

    if let Ok(env_path) = std::env::var("FUXICO_CONFIG") {
        if let Ok(content) = std::fs::read_to_string(&env_path) {
            return toml::from_str(&content).unwrap_or_default();
        }
    }

    let home = std::env::var("HOME").unwrap_or_default();
    let xdg = std::env::var("XDG_CONFIG_HOME").unwrap_or_else(|_| format!("{}/.config", home));

    let candidates = [
        format!("{}/fux/config.toml", xdg),
        format!("{}/.fuxrc", home),
    ];

    for path in &candidates {
        if let Ok(content) = std::fs::read_to_string(path) {
            if let Ok(cfg) = toml::from_str(&content) {
                return cfg;
            }
        }
    }

    FileConfig::default()
}

fn copy_to_clipboard(text: &str) {
    let candidates = [
        ("wl-copy", vec![]),
        ("xclip", vec!["-selection", "clipboard"]),
        ("pbcopy", vec![]),
    ];

    for (cmd, args) in candidates {
        if let Ok(mut child) = std::process::Command::new(cmd)
            .args(&args)
            .stdin(std::process::Stdio::piped())
            .spawn()
        {
            if let Some(mut stdin) = child.stdin.take() {
                let _ = stdin.write_all(text.as_bytes());
            }
            let _ = child.wait();
            return;
        }
    }
}

fn main() -> Result<(), Box<dyn std::error::Error>> {
    let args: Vec<String> = std::env::args().collect();
    if args.iter().any(|a| a == "--ajuda" || a == "--socorro") {
        use clap::CommandFactory;
        let mut cmd = Cli::command();
        cmd.print_help()?;
        println!();
        return Ok(());
    }

    let cli = Cli::parse();
    let file_cfg = load_file_config(cli.config.as_deref());

    // Resolve merged settings (CLI > Env > Config File > Default)
    let server_raw = cli
        .server
        .or(file_cfg.server)
        .or(file_cfg.servidor)
        .unwrap_or_else(|| "http://localhost:8080".to_string());
    let server = server_raw.trim_end_matches('/');

    let token = cli
        .token
        .or(file_cfg.token)
        .or(file_cfg.ficha)
        .unwrap_or_default();

    let dialect_raw = cli
        .dialect
        .or(file_cfg.dialect)
        .or(file_cfg.dialeto)
        .or(file_cfg.sotaque)
        .unwrap_or_else(|| "nullpointer".to_string());

    let is_rustypaste = matches!(
        dialect_raw.to_lowercase().as_str(),
        "rustypaste" | "rpaste" | "rusty"
    );

    let expires = cli
        .expires
        .or(file_cfg.expires)
        .or(file_cfg.caduca);

    let oneshot = cli.oneshot
        || file_cfg.oneshot.unwrap_or(false)
        || file_cfg.queima.unwrap_or(false);

    let secret = cli.secret
        || file_cfg.secret.unwrap_or(false)
        || file_cfg.segredo.unwrap_or(false);

    let quiet = cli.quiet;

    // 1. Delete operation
    if let Some(slug) = cli.delete {
        let url = format!("{}/{}", server, slug);

        if !is_rustypaste {
            // Nullpointer (0x0.st) management deletion: POST /<slug> with token & delete
            let boundary = "------------------------fux_boundary";
            let mut body = Vec::new();
            append_field(&mut body, boundary, "token", &token);
            append_field(&mut body, boundary, "delete", "");
            body.extend_from_slice(format!("--{}--\r\n", boundary).as_bytes());

            let post_resp = ureq::post(&url)
                .header(
                    "Content-Type",
                    format!("multipart/form-data; boundary={}", boundary),
                )
                .send(body.as_slice());

            if post_resp.is_ok() {
                if !quiet {
                    println!("Successfully deleted: {}", slug);
                }
                return Ok(());
            }
        }

        // Rustypaste style or fallback: DELETE /<slug>
        let resp = ureq::delete(&url)
            .header("Authorization", format!("Bearer {}", token))
            .call();

        match resp {
            Ok(_) => {
                if !quiet {
                    println!("Successfully deleted: {}", slug);
                }
                return Ok(());
            }
            Err(e) => {
                handle_req_error(e);
            }
        }
    }

    // 2. List operation
    if cli.list {
        let url = format!("{}/list", server);
        let mut resp = ureq::get(&url)
            .header("Authorization", format!("Bearer {}", token))
            .call()
            .unwrap_or_else(|e| handle_req_error(e));
        let body: String = resp.body_mut().read_to_string()?;
        print!("{}", body);
        return Ok(());
    }

    // 2.3 Git Repository Initialization
    if let Some(ref repo_name) = cli.git_init {
        if !quiet {
            eprintln!("inicializando repositório git efêmero no fuxico...");
        }

        let mut payload = serde_json::Map::new();
        payload.insert("name".to_string(), serde_json::Value::String(repo_name.clone()));
        if let Some(ref exp) = expires {
            payload.insert("expires".to_string(), serde_json::Value::String(exp.clone()));
        }

        let mut req = ureq::post(&format!("{}/api/git", server))
            .header("Content-Type", "application/json");

        if !token.is_empty() {
            req = req.header("Authorization", format!("Bearer {}", token));
        }

        let mut resp = req
            .send_json(serde_json::Value::Object(payload))
            .unwrap_or_else(|e| handle_req_error(e));

        let resp_json: serde_json::Value = resp.body_mut().read_json()?;
        let clone_url = resp_json
            .get("clone_url")
            .and_then(|v| v.as_str())
            .unwrap_or("")
            .to_string();

        if let Some(tok) = resp_json.get("x_token").and_then(|v| v.as_str()) {
            if !quiet {
                eprintln!("ficha do repositório (X-Token): {}", tok);
            }
        }

        if !quiet {
            eprintln!("remote 'fuxico' disponível para push:");
            eprintln!("git remote add fuxico {}", clone_url);
            eprintln!("git push -u fuxico main");
        }

        println!("{}", clone_url);
        if cli.copy {
            copy_to_clipboard(&clone_url);
        }
        return Ok(());
    }

    // 2.5 Gist Upload
    if !cli.gist.is_empty() {
        if !quiet {
            eprintln!("empacotando o gist com {} arquivos...", cli.gist.len());
        }

        let mut files_map = serde_json::Map::new();
        for path in &cli.gist {
            let content = std::fs::read_to_string(path).map_err(|e| {
                eprintln!("arquivo não encontrado ou inválido {}: {}", path.display(), e);
                std::process::exit(1);
            })?;
            let file_name = path
                .file_name()
                .and_then(|n| n.to_str())
                .unwrap_or("file")
                .to_string();
            let mut file_obj = serde_json::Map::new();
            file_obj.insert("content".to_string(), serde_json::Value::String(content));
            files_map.insert(file_name, serde_json::Value::Object(file_obj));
        }

        let mut payload = serde_json::Map::new();
        payload.insert("files".to_string(), serde_json::Value::Object(files_map));
        if let Some(ref name) = cli.name {
            payload.insert("title".to_string(), serde_json::Value::String(name.clone()));
        }
        if let Some(ref exp) = expires {
            payload.insert("expires".to_string(), serde_json::Value::String(exp.clone()));
        }
        if oneshot {
            payload.insert("oneshot".to_string(), serde_json::Value::Bool(true));
        }
        if secret {
            payload.insert("secret".to_string(), serde_json::Value::Bool(true));
        }

        let url = format!("{}/api/gist", server.trim_end_matches('/'));
        let mut req = ureq::post(&url);

        if !token.is_empty() {
            req = req.header("Authorization", format!("Bearer {}", token));
        }

        let mut resp = req
            .send_json(serde_json::Value::Object(payload))
            .unwrap_or_else(|e| handle_req_error(e));

        if !quiet {
            if let Some(x_token) = resp.headers().get("x-token").and_then(|v| v.to_str().ok()) {
                eprintln!("Management Token (X-Token): {}", x_token);
            }
        }

        let body: String = resp.body_mut().read_to_string()?;
        let final_url = if let Ok(json_val) = serde_json::from_str::<serde_json::Value>(&body) {
            json_val.get("url").and_then(|u| u.as_str()).unwrap_or(&body).trim().to_string()
        } else {
            body.trim().to_string()
        };

        if cli.copy {
            copy_to_clipboard(&final_url);
            if !quiet {
                eprintln!("link copiado pro clipboard.");
            }
        }

        println!("{}", final_url);
        return Ok(());
    }

    // 3. Shorten URL
    if let Some(target_url) = cli.url {
        let boundary = "------------------------fux_boundary";
        let mut body = Vec::new();

        let mut req = ureq::post(server)
            .header("Authorization", format!("Bearer {}", token))
            .header(
                "Content-Type",
                format!("multipart/form-data; boundary={}", boundary),
            );

        if is_rustypaste {
            append_field(&mut body, boundary, "url", &target_url);
            if let Some(ref exp) = expires {
                req = req.header("expire", exp);
            }
            if oneshot {
                req = req.header("oneshot", "true");
            }
        } else {
            append_field(&mut body, boundary, "shorten", &target_url);
            if let Some(ref exp) = expires {
                append_field(&mut body, boundary, "expires", exp);
            }
            if oneshot {
                append_field(&mut body, boundary, "oneshot", "true");
            }
        }

        if secret {
            append_field(&mut body, boundary, "secret", "");
        }
        if let Some(ref name) = cli.name {
            append_field(&mut body, boundary, "customName", name);
        }
        body.extend_from_slice(format!("--{}--\r\n", boundary).as_bytes());

        let mut resp = req
            .send(body.as_slice())
            .unwrap_or_else(|e| handle_req_error(e));

        if !quiet {
            if let Some(x_token) = resp.headers().get("x-token").and_then(|v| v.to_str().ok()) {
                eprintln!("Management Token (X-Token): {}", x_token);
            }
        }

        let result_url = resp.body_mut().read_to_string()?;
        print!("{}", result_url);

        if cli.copy {
            copy_to_clipboard(result_url.trim());
        }
        return Ok(());
    }

    // 4. File Upload (from file argument or stdin)
    let (file_data, file_name) = match cli.file {
        Some(path) => {
            let mut file = File::open(&path)?;
            let mut data = Vec::new();
            file.read_to_end(&mut data)?;
            let name = path
                .file_name()
                .map(|n| n.to_string_lossy().to_string())
                .unwrap_or_else(|| "upload.bin".to_string());
            (data, name)
        }
        None => {
            let mut data = Vec::new();
            io::stdin().read_to_end(&mut data)?;
            if data.is_empty() {
                eprintln!("Error: No file specified and stdin was empty. Use --help for usage.");
                std::process::exit(1);
            }
            (data, "stdin.txt".to_string())
        }
    };

    let boundary = "------------------------fux_boundary";
    let mut body = Vec::new();

    let mut req = ureq::post(server)
        .header("Authorization", format!("Bearer {}", token))
        .header(
            "Content-Type",
            format!("multipart/form-data; boundary={}", boundary),
        );

    if is_rustypaste {
        if let Some(ref exp) = expires {
            req = req.header("expire", exp);
        }
        if oneshot {
            req = req.header("oneshot", "true");
        }
    } else {
        if let Some(ref exp) = expires {
            append_field(&mut body, boundary, "expires", exp);
        }
        if oneshot {
            append_field(&mut body, boundary, "oneshot", "true");
        }
    }

    if secret {
        append_field(&mut body, boundary, "secret", "");
    }
    if let Some(ref name) = cli.name {
        append_field(&mut body, boundary, "customName", name);
    }

    // Append file field
    body.extend_from_slice(format!("--{}\r\n", boundary).as_bytes());
    body.extend_from_slice(
        format!(
            "Content-Disposition: form-data; name=\"file\"; filename=\"{}\"\r\nContent-Type: application/octet-stream\r\n\r\n",
            file_name
        ).as_bytes()
    );
    body.extend_from_slice(&file_data);
    body.extend_from_slice(b"\r\n");
    body.extend_from_slice(format!("--{}--\r\n", boundary).as_bytes());

    let mut resp = req
        .send(body.as_slice())
        .unwrap_or_else(|e| handle_req_error(e));

    if !quiet {
        if let Some(x_token) = resp.headers().get("x-token").and_then(|v| v.to_str().ok()) {
            eprintln!("Management Token (X-Token): {}", x_token);
        }
    }

    let result_url = resp.body_mut().read_to_string()?;
    print!("{}", result_url);

    if cli.copy {
        copy_to_clipboard(result_url.trim());
    }

    Ok(())
}

fn append_field(body: &mut Vec<u8>, boundary: &str, name: &str, value: &str) {
    body.extend_from_slice(format!("--{}\r\n", boundary).as_bytes());
    body.extend_from_slice(
        format!(
            "Content-Disposition: form-data; name=\"{}\"\r\n\r\n{}\r\n",
            name, value
        )
        .as_bytes(),
    );
}

fn handle_req_error(err: ureq::Error) -> ! {
    match err {
        ureq::Error::StatusCode(401) => {
            eprintln!("[fux] acesso negado (HTTP 401): ficha/token de autenticação incorreto ou ausente.");
            eprintln!("[fux] dica: use 'fux -t <sua-ficha>' ou configure 'token = \"...\"' em ~/.config/fux/config.toml");
        }
        ureq::Error::StatusCode(400) => {
            eprintln!("[fux] requisição rejeitada (HTTP 400): verifique os parâmetros ou a extensão do arquivo.");
        }
        ureq::Error::StatusCode(413) => {
            eprintln!("[fux] arquivo grande demais (HTTP 413): o tamanho ultrapassa o limite máximo do servidor.");
        }
        ureq::Error::StatusCode(code) => {
            eprintln!("[fux] erro do servidor (HTTP {}).", code);
        }
        other => {
            eprintln!("[fux] erro de comunicação/rede: {}", other);
        }
    }
    std::process::exit(1);
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_deserialize_config_en_and_pt() {
        let toml_str = r#"
            servidor = "http://test.local:8080"
            ficha = "token123"
            dialeto = "rustypaste"
            caduca = "2h"
            queima = true
        "#;
        let cfg: FileConfig = toml::from_str(toml_str).unwrap();
        assert_eq!(cfg.servidor.as_deref(), Some("http://test.local:8080"));
        assert_eq!(cfg.ficha.as_deref(), Some("token123"));
        assert_eq!(cfg.dialeto.as_deref(), Some("rustypaste"));
        assert_eq!(cfg.caduca.as_deref(), Some("2h"));
        assert_eq!(cfg.queima, Some(true));
    }

    #[test]
    fn test_deserialize_config_standard_en() {
        let toml_str = r#"
            server = "https://fuxico.cc"
            token = "secret-token"
            dialect = "nullpointer"
            expires = "24h"
            oneshot = false
        "#;
        let cfg: FileConfig = toml::from_str(toml_str).unwrap();
        assert_eq!(cfg.server.as_deref(), Some("https://fuxico.cc"));
        assert_eq!(cfg.token.as_deref(), Some("secret-token"));
        assert_eq!(cfg.dialect.as_deref(), Some("nullpointer"));
        assert_eq!(cfg.expires.as_deref(), Some("24h"));
        assert_eq!(cfg.oneshot, Some(false));
    }
}
