use clap::Parser;
use std::fs::File;
use std::io::{self, Read};
use std::path::PathBuf;

#[derive(Parser)]
#[command(name = "picoshare")]
#[command(about = "Fast, minimalist CLI client for PicoShare", long_about = None)]
struct Cli {
    /// Target file to upload (or omit when shortening URL or managing)
    #[arg(value_name = "FILE")]
    file: Option<PathBuf>,

    /// Server URL
    #[arg(short = 'S', long, env = "PICOSHARE_URL", default_value = "http://localhost:8080")]
    server: String,

    /// Authorization token
    #[arg(short = 't', long, env = "PICOSHARE_TOKEN", default_value = "tatanka")]
    token: String,

    /// Shorten a destination URL instead of uploading a file
    #[arg(short = 'u', long)]
    shorten: Option<String>,

    /// Set expiration (e.g. "24" for 24 hours, "10m", "7d", "1y")
    #[arg(short = 'e', long)]
    expires: Option<String>,

    /// Burn after reading (delete after single access)
    #[arg(short = 'o', long)]
    oneshot: bool,

    /// Generate an unguessable 16-character secret slug
    #[arg(short = 's', long)]
    secret: bool,

    /// Custom slug name
    #[arg(short = 'n', long)]
    name: Option<String>,

    /// Delete an item by slug
    #[arg(short = 'd', long)]
    delete: Option<String>,

    /// List active files in JSON
    #[arg(short = 'l', long)]
    list: bool,
}

fn main() -> Result<(), Box<dyn std::error::Error>> {
    let cli = Cli::parse();
    let server = cli.server.trim_end_matches('/');

    // 1. Delete operation
    if let Some(slug) = cli.delete {
        let url = format!("{}/{}", server, slug);
        let resp = ureq::delete(&url)
            .set("Authorization", &format!("Bearer {}", cli.token))
            .call();

        match resp {
            Ok(_) => {
                println!("Successfully deleted: {}", slug);
                return Ok(());
            }
            Err(e) => {
                // Try 0x0.st POST management deletion as fallback
                let boundary = "------------------------picoshare_boundary";
                let mut body = Vec::new();
                append_field(&mut body, boundary, "token", &cli.token);
                append_field(&mut body, boundary, "delete", "");
                body.extend_from_slice(format!("--{}--\r\n", boundary).as_bytes());

                let post_resp = ureq::post(&url)
                    .set("Content-Type", &format!("multipart/form-data; boundary={}", boundary))
                    .send_bytes(&body);

                match post_resp {
                    Ok(_) => {
                        println!("Successfully deleted: {}", slug);
                        return Ok(());
                    }
                    Err(_) => {
                        eprintln!("Error deleting {}: {}", slug, e);
                        std::process::exit(1);
                    }
                }
            }
        }
    }

    // 2. List operation
    if cli.list {
        let url = format!("{}/list", server);
        let resp = ureq::get(&url)
            .set("Authorization", &format!("Bearer {}", cli.token))
            .call()?;
        let body: String = resp.into_string()?;
        println!("{}", body);
        return Ok(());
    }

    // 3. Shorten URL
    if let Some(target_url) = cli.shorten {
        let boundary = "------------------------picoshare_boundary";
        let mut body = Vec::new();
        append_field(&mut body, boundary, "shorten", &target_url);

        if let Some(exp) = cli.expires {
            append_field(&mut body, boundary, "expires", &exp);
        }
        if cli.oneshot {
            append_field(&mut body, boundary, "oneshot", "true");
        }
        if let Some(name) = cli.name {
            append_field(&mut body, boundary, "customName", &name);
        }
        body.extend_from_slice(format!("--{}--\r\n", boundary).as_bytes());

        let resp = ureq::post(server)
            .set("Authorization", &format!("Bearer {}", cli.token))
            .set("Content-Type", &format!("multipart/form-data; boundary={}", boundary))
            .send_bytes(&body)?;

        if let Some(x_token) = resp.header("x-token") {
            eprintln!("Management Token (X-Token): {}", x_token);
        }
        print!("{}", resp.into_string()?);
        return Ok(());
    }

    // 4. File Upload
    let (file_data, file_name) = match cli.file {
        Some(path) => {
            let mut file = File::open(&path)?;
            let mut data = Vec::new();
            file.read_to_end(&mut data)?;
            let name = path.file_name()
                .map(|n| n.to_string_lossy().to_string())
                .unwrap_or_else(|| "upload.bin".to_string());
            (data, name)
        }
        None => {
            // Read from stdin
            let mut data = Vec::new();
            io::stdin().read_to_end(&mut data)?;
            if data.is_empty() {
                eprintln!("Error: No file specified and stdin was empty. Use --help for usage.");
                std::process::exit(1);
            }
            (data, "stdin.txt".to_string())
        }
    };

    let boundary = "------------------------picoshare_boundary";
    let mut body = Vec::new();

    if let Some(exp) = cli.expires {
        append_field(&mut body, boundary, "expires", &exp);
    }
    if cli.oneshot {
        append_field(&mut body, boundary, "oneshot", "true");
    }
    if cli.secret {
        append_field(&mut body, boundary, "secret", "");
    }
    if let Some(name) = cli.name {
        append_field(&mut body, boundary, "customName", &name);
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

    let resp = ureq::post(server)
        .set("Authorization", &format!("Bearer {}", cli.token))
        .set("Content-Type", &format!("multipart/form-data; boundary={}", boundary))
        .send_bytes(&body)?;

    if let Some(x_token) = resp.header("x-token") {
        eprintln!("Management Token (X-Token): {}", x_token);
    }
    print!("{}", resp.into_string()?);

    Ok(())
}

fn append_field(body: &mut Vec<u8>, boundary: &str, name: &str, value: &str) {
    body.extend_from_slice(format!("--{}\r\n", boundary).as_bytes());
    body.extend_from_slice(
        format!("Content-Disposition: form-data; name=\"{}\"\r\n\r\n{}\r\n", name, value).as_bytes()
    );
}
