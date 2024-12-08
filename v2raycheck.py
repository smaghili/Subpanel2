import re
import json
import base64
import urllib.parse
import subprocess
import os
import time
import requests
import asyncio
import aiohttp
import argparse
from typing import Dict, List, Optional
from dataclasses import dataclass, asdict
from urllib.parse import urlparse, parse_qs
from concurrent.futures import ThreadPoolExecutor
from itertools import islice
from collections import defaultdict

COUNTRY_EMOJIS = {
    "Iran": "🇮🇷",
    "France": "🇫🇷",
    "Germany": "🇩🇪",
    "Netherlands": "🇳🇱",
    "The Netherlands": "🇳🇱",
    "United States": "🇺🇸",
    "Canada": "🇨🇦",
    "United Kingdom": "🇬🇧",
    "Japan": "🇯🇵",
    "Singapore": "🇸🇬",
    "Hong Kong": "🇭🇰",
    "Russia": "🇷🇺",
    "Turkey": "🇹🇷",
    "Brazil": "🇧🇷",
    "India": "🇮🇳",
    "Australia": "🇦🇺",
    "Sweden": "🇸🇪",
    "Norway": "🇳🇴",
    "Finland": "🇫🇮",
    "Denmark": "🇩🇰",
    "Italy": "🇮🇹",
    "Spain": "🇪🇸",
    "Belgium": "🇧🇪",
    "Latvia": "🇱🇻",
    "Poland": "🇵🇱",
    "United Arab Emirates": "🇦🇪",
    "UAE": "🇦🇪",
    "Türkiye": "🇹🇷",
    "Mexico": "🇲🇽",
    "Austria": "🇦🇹",
    "Bulgaria": "🇧🇬",
    "Romania": "🇷🇴",
    "Greece": "🇬🇷",
    "Croatia": "🇭🇷",
    "Serbia": "🇷🇸",
    "Slovenia": "🇸🇮",
    "Slovakia": "🇸🇰",
    "Czech Republic": "🇨🇿",
    "Hungary": "🇭🇺",
    "Switzerland": "🇨🇭",
    "Portugal": "🇵🇹",
    "Ireland": "🇮🇪",
    "Iceland": "🇮🇸",
    "Estonia": "🇪🇪",
    "Lithuania": "🇱🇹",
    "Ukraine": "🇺🇦",
    "Moldova": "🇲🇩",
    "Belarus": "🇧🇾",
    "Georgia": "🇬🇪",
    "Armenia": "🇦🇲",
    "Azerbaijan": "🇦🇿",
    "Kazakhstan": "🇰🇿",
    "Uzbekistan": "🇺🇿",
    "Kyrgyzstan": "🇰🇬",
    "Tajikistan": "🇹🇯",
    "Turkmenistan": "🇹🇲",
    "Afghanistan": "🇦🇫",
    "Pakistan": "🇵🇰",
    "Bangladesh": "🇧🇩",
    "Nepal": "🇳🇵",
    "Sri Lanka": "🇱🇰",
    "Unknown": "🌍"
}

# Counter class for managing config numbers
class ConfigCounter:
    def __init__(self):
        self.counters = defaultdict(int)
    
    def get_next_number(self, key: str) -> int:
        """Get next number for a specific config type and increment counter"""
        self.counters[key] += 1
        return self.counters[key]
    
    def reset(self):
        """Reset all counters"""
        self.counters.clear()

# Global counter instance
config_counter = ConfigCounter()

import ipaddress

@dataclass
class ConfigData:
    type: str
    name: str = ""
    server: str = ""
    port: int = 443
    uuid: str = ""
    path: str = "/"
    tls: str = "tls"
    network: str = "tcp"
    security: str = "none"
    encryption: str = "none"
    host: str = ""
    sni: str = ""
    fp: str = ""
    alpn: str = ""
    flow: str = ""
    aid: int = 0
    method: str = "auto"
    password: str = ""
    headerType: str = ""
    xtls: bool = False
    grpc_service_name: str = ""
    pbk: str = ""
    sid: str = ""
    allowInsecure: bool = False

def decode_vmess_base64(vmess_str: str) -> Dict:
    if vmess_str.startswith("vmess://"):
        vmess_str = vmess_str[8:]
    
    try:
        decoded = base64.b64decode(vmess_str + "=" * (-len(vmess_str) % 4))
        return json.loads(decoded)
    except:
        return {}

def parse_vless(url: str) -> ConfigData:
    url = url.replace("vless://", "")
    
    if "@" in url:
        user_info, server_info = url.split("@", 1)
    else:
        return ConfigData(type="vless")
    
    parsed = urlparse(f"https://{server_info}")
    params = parse_qs(parsed.query)
    
    config = ConfigData(type="vless")
    config.uuid = user_info
    config.server = parsed.hostname or ""
    config.port = int(parsed.port or 443)
    
    if "type" in params:
        config.network = params["type"][0]
    if "path" in params:
        config.path = params["path"][0]
    if "security" in params:
        config.security = params["security"][0]
    if "encryption" in params:
        config.encryption = params["encryption"][0]
    if "host" in params:
        config.host = params["host"][0]
    if "sni" in params:
        config.sni = params["sni"][0]
    if "fp" in params:
        config.fp = params["fp"][0]
    if "alpn" in params:
        config.alpn = params["alpn"][0]
    if "flow" in params:
        config.flow = params["flow"][0]
    if "headerType" in params:
        config.headerType = params["headerType"][0]
    if "xtls" in params:
        config.xtls = params["xtls"][0].lower() == "true"
    if "serviceName" in params:
        config.grpc_service_name = params["serviceName"][0]
    if "pbk" in params:
        config.pbk = params["pbk"][0]
    if "sid" in params:
        config.sid = params["sid"][0]
    
    return config

def parse_vmess(url: str) -> ConfigData:
    if not url.startswith("vmess://"):
        return ConfigData(type="vmess")
    
    vmess_data = decode_vmess_base64(url)
    
    config = ConfigData(type="vmess")
    config.server = vmess_data.get("add", "")
    config.port = int(vmess_data.get("port", 443))
    config.uuid = vmess_data.get("id", "")
    config.aid = int(vmess_data.get("aid", 0))
    config.network = vmess_data.get("net", "tcp")
    config.path = vmess_data.get("path", "/")
    config.host = vmess_data.get("host", "")
    config.tls = "tls" if vmess_data.get("tls") == "tls" else "none"
    config.headerType = vmess_data.get("type", "")
    
    return config

def parse_trojan(url: str) -> ConfigData:
    if not url.startswith("trojan://"):
        return ConfigData(type="trojan")
    
    url = url.replace("trojan://", "")
    
    if "@" in url:
        password, server_info = url.split("@", 1)
    else:
        return ConfigData(type="trojan")
    
    parsed = urlparse(f"https://{server_info}")
    params = parse_qs(parsed.query)
    
    config = ConfigData(type="trojan")
    config.password = password
    config.server = parsed.hostname or ""
    config.port = int(parsed.port or 443)
    
    config.network = params.get("type", ["tcp"])[0]
    config.path = params.get("path", ["/"])[0]
    config.security = params.get("security", ["tls"])[0]
    config.sni = params.get("sni", [config.server])[0]
    config.host = params.get("host", [config.sni])[0]
    config.headerType = params.get("headerType", ["none"])[0]
    config.fp = params.get("fp", ["chrome"])[0]
    config.alpn = params.get("alpn", ["h3,h2,http/1.1"])[0]
    config.allowInsecure = True
    return config

def parse_shadowsocks(url: str) -> ConfigData:
    if not url.startswith("ss://"):
        return ConfigData(type="shadowsocks")
    
    url = url.replace("ss://", "")
    
    try:
        if "@" in url:
            user_info, server_info = url.split("@", 1)
            decoded = base64.b64decode(user_info + "=" * (-len(user_info) % 4)).decode()
            method, password = decoded.split(":", 1)
        else:
            decoded = base64.b64decode(url + "=" * (-len(url) % 4)).decode()
            method, rest = decoded.split(":", 1)
            password, server_info = rest.split("@", 1)
            
        parsed = urlparse(f"https://{server_info}")
        
        config = ConfigData(type="shadowsocks")
        config.method = method
        config.password = password
        config.server = parsed.hostname or ""
        config.port = int(parsed.port or 443)
        
        return config
    except:
        return ConfigData(type="shadowsocks")

def config_to_json(config_url: str, inbound_port: int = 1080, output_filename: str = "config_output.json") -> Dict:
    """Convert various config formats to Xray JSON format
    
    Args:
        config_url: The config URL string
        inbound_port: The port number for inbound SOCKS connection
    """
    if config_url.startswith("vless://"):
        config = parse_vless(config_url)
    elif config_url.startswith("vmess://"):
        config = parse_vmess(config_url)
    elif config_url.startswith("trojan://"):
        config = parse_trojan(config_url)
    elif config_url.startswith("ss://"):
        config = parse_shadowsocks(config_url)
    else:
        return {"error": "Unsupported config format"}
    
    xray_config = {
        "inbounds": [{
            "port": inbound_port,
            "protocol": "socks",
            "settings": {
                "udp": True
            }
        }],
        "outbounds": [{
            "protocol": config.type,
            "settings": {},
            "streamSettings": {
                "network": config.network,
                "security": config.security,
            }
        }]
    }
    
    # Add TLS or XTLS settings if security is not none
    if config.security != "none":
        xray_config["outbounds"][0]["streamSettings"]["tlsSettings"] = {
            "serverName": config.sni or config.host or config.server,
            "fingerprint": config.fp or "firefox",
            "alpn": [config.alpn] if config.alpn else [],
            "allowInsecure": config.allowInsecure
        }
    if config.xtls:
        xray_config["outbounds"][0]["streamSettings"]["xtlsSettings"] = {
            "serverName": config.sni or config.host or config.server
        }
    
    # Handle Reality settings
    if config.security == "reality":
        xray_config["outbounds"][0]["streamSettings"]["realitySettings"] = {
            "publicKey": config.pbk,
            "shortId": config.sid,
            "serverName": config.sni,
            "fingerprint": config.fp or "firefox"
        }
    
    # Handle different network types and settings
    if config.network == "tcp" and config.headerType == "http":
        xray_config["outbounds"][0]["streamSettings"]["tcpSettings"] = {
            "header": {
                "type": "http",
                "request": {
                    "version": "1.1",
                    "method": "GET",
                    "path": [config.path] if config.path else ["/"],
                    "headers": {
                        "Host": [config.host] if config.host else [],
                        "User-Agent": [
                            "Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/53.0.2785.143 Safari/537.36",
                            "Mozilla/5.0 (iPhone; CPU iPhone OS 10_0_2 like Mac OS X) AppleWebKit/601.1 (KHTML, like Gecko) CriOS/53.0.2785.109 Mobile/14A456 Safari/601.1.46"
                        ],
                        "Accept-Encoding": ["gzip, deflate"],
                        "Connection": ["keep-alive"],
                        "Pragma": "no-cache"
                    }
                }
            }
        }
    elif config.network == "http" or (config.type == "vless" and config.headerType == "http"):
        # Handle both http and httpupgrade
        xray_config["outbounds"][0]["streamSettings"]["httpSettings"] = {
            "path": config.path.split(",")[0] if "," in config.path else config.path,  # Take first path if multiple
            "host": config.host.split(",") if config.host else [],
            "method": "GET"
        }
    elif config.network == "httpupgrade":
        # Add httpupgradeSettings if network is httpupgrade
        xray_config["outbounds"][0]["streamSettings"]["httpupgradeSettings"] = {
            "path": config.path,
            "host": config.host
        }
    elif config.network == "ws":
        xray_config["outbounds"][0]["streamSettings"]["wsSettings"] = {
            "path": config.path,
            "headers": {
                "Host": config.host or config.sni
            }
        }
    elif config.network == "grpc":
        xray_config["outbounds"][0]["streamSettings"]["grpcSettings"] = {
            "serviceName": config.grpc_service_name,
            "multiMode": False
        }
    elif config.network == "quic":
        xray_config["outbounds"][0]["streamSettings"]["quicSettings"] = {
            "security": config.security,
            "key": config.password,
            "header": {
                "type": config.headerType
            }
        }
    
    outbound_settings = xray_config["outbounds"][0]["settings"]
    
    if config.type == "vless":
        outbound_settings["vnext"] = [{
            "address": config.server,
            "port": config.port,
            "users": [{
                "id": config.uuid,
                "encryption": config.encryption,
                "flow": config.flow if config.flow else ""
            }]
        }]
    elif config.type == "vmess":
        outbound_settings["vnext"] = [{
            "address": config.server,
            "port": config.port,
            "users": [{
                "id": config.uuid,
                "alterId": config.aid,
                "security": "auto"
            }]
        }]
    elif config.type == "trojan":
        outbound_settings["servers"] = [{
            "address": config.server,
            "port": config.port,
            "password": config.password
        }]
    elif config.type == "shadowsocks":
        outbound_settings["servers"] = [{
            "address": config.server,
            "port": config.port,
            "method": config.method,
            "password": config.password
        }]
    
    # Save to output JSON file
    with open(output_filename, 'w') as f:
        json.dump(xray_config, f, indent=2)
    
    return xray_config

def fetch_subscription(url: str) -> List[str]:
    """Fetch and decode subscription link content"""
    try:
        response = requests.get(url)
        response.raise_for_status()
        content = response.text.strip()
        
        # Try base64 decoding if content looks encoded
        try:
            decoded = base64.b64decode(content + "=" * (-len(content) % 4)).decode()
            configs = decoded.splitlines()
        except:
            configs = content.splitlines()
            
        return [line.strip() for line in configs if line.strip()]
    except Exception as e:
        print(f"Error fetching subscription: {str(e)}")
        return []

def save_working_config(config: str, save_path: str, position: str = 'end') -> bool:
    """Save a single working config to the specified file.
    
    Args:
        config: Config string to save
        save_path: Path to save file
        position: 'start' to add at beginning, 'end' to append (default: 'end')
    """
    try:
        if not save_path:
            return True

        os.makedirs(os.path.dirname(save_path), exist_ok=True)
        
        # Check for existing configs
        existing_configs = []
        if os.path.exists(save_path):
            with open(save_path, 'r', encoding='utf-8') as f:
                existing_configs = [line.strip() for line in f]
        
        # Extract base config (without name) for comparison
        base_config = config.split('#')[0] if '#' in config else config
        
        # Check if config already exists
        for existing_config in existing_configs:
            existing_base = existing_config.split('#')[0] if '#' in existing_config else existing_config
            if existing_base == base_config:
                print(f"\033[93m[SKIP]\033[0m Config already exists in {save_path}")
                return False

        if position == "start" and existing_configs:
            # Add to beginning of file
            existing_configs.insert(0, config)
            with open(save_path, 'w', encoding='utf-8') as f:
                for cfg in existing_configs:
                    f.write(f"{cfg}\n")
        else:
            # Append to end of file (default behavior)
            with open(save_path, 'a', encoding='utf-8') as f:
                f.write(f"{config}\n")
                
        print(f"\033[92m[SAVED]\033[0m Config saved to {save_path}")
        return True
    
    except Exception as e:
        if save_path:
            print(f"\033[91mError saving config: {str(e)}\033[0m")
        return False

async def measure_ping(session, proxy_url: str, count: int = 4) -> float:
    """Measure average ping using Google's generate_204 endpoint"""
    total_time = 0
    successful_pings = 0
    test_url = "http://www.google.com/generate_204"
    
    for _ in range(count):
        try:
            start_time = time.time()
            async with session.get(
                test_url,
                proxy=proxy_url,
                timeout=10,
                allow_redirects=False
            ) as response:
                if response.status == 204:
                    end_time = time.time()
                    total_time += (end_time - start_time) * 1000  # Convert to ms
                    successful_pings += 1
        except:
            continue
            
    if successful_pings == 0:
        return float('inf')
    return total_time / successful_pings

def generate_hamshahri_name(config: str, protocol_type: str, network_type: str, country: str) -> str:
    """
    Generate a new name for config in format: Hamshahri-<NETWORK>-<FLAG>-<NUMBER>
    """
    # Get country emoji
    emoji = get_country_emoji(country)
    
    # Map network type to short format
    network_map = {
        'ws': 'WS',
        'tcp': 'TCP',
        'grpc': 'GRPC',
        'h2': 'H2',
        'http': 'HTTP',
        'kcp': 'KCP',
        'quic': 'QUIC'
    }
    network_code = network_map.get(network_type.lower(), 'TCP')
    
    # Generate counter key based on protocol and network type
    counter_key = f"{protocol_type}-{network_code}-{country}"
    
    # Get next number for this type of config
    number = config_counter.get_next_number(counter_key)
    
    # Generate final name
    return f"Hamshahri-{network_code}-{emoji}-{number}"
    
async def test_config(config_url: str, port: int, save_path: str, position: str = "end", measure_latency: bool = False, name_suffix: str = None, checkname: bool = False) -> Dict:
    """
    Modified to include 'checkname' parameter.
    """
    process = None
    process_curl = None
    temp_filename = None
    
    try:
        config = config_to_json(config_url, port)
        if "error" in config:
            return {"config": config_url, "status": "error", "message": config["error"]}
    
        temp_filename = f"config_{port}.json"
        with open(temp_filename, 'w') as f:
            json.dump(config, f, indent=2)
    
        process = subprocess.Popen(
            ["/usr/local/bin/xray", "run", "-c", temp_filename],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL
        )
    
        await asyncio.sleep(2)
    
        try:
            process_curl = await asyncio.create_subprocess_exec(
                "curl", "-s", "-x", f"socks5h://localhost:{port}", 
                "--connect-timeout", "10",
                "http://ip-api.com/json",
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE
            )
            
            try:
                stdout, stderr = await asyncio.wait_for(process_curl.communicate(), timeout=15)
                if stdout:
                    output = stdout.decode().strip()
                    try:
                        data = json.loads(output)
                        country = data.get("country", "Unknown")
                        ip_address = data.get("query", "Unknown")
                        
                        if ip_address == "Unknown":
                            return {
                                "config": config_url,
                                "status": "failed",
                                "message": "Failed to retrieve IP or country",
                                "port": port
                            }
                        
                        protocol_type = ""
                        network_type = "tcp"
                        
                        if config_url.startswith("vless://"):
                            protocol_type = "vless"
                            parsed = urlparse(config_url)
                            query_params = parse_qs(parsed.query)
                            network_type = query_params.get('type', ['tcp'])[0]
                        elif config_url.startswith("vmess://"):
                            protocol_type = "vmess"
                            try:
                                vmess_data = decode_vmess_base64(config_url)
                                network_type = vmess_data.get('net', 'tcp')
                            except:
                                network_type = "tcp"
                        elif config_url.startswith("trojan://"):
                            protocol_type = "trojan"
                            parsed = urlparse(config_url)
                            query_params = parse_qs(parsed.query)
                            network_type = query_params.get('type', ['tcp'])[0]
                        elif config_url.startswith("ss://"):
                            protocol_type = "shadowsocks"
                            network_type = "tcp"
                        
                        # Extract existing name if present
                        existing_name = ""
                        if '#' in config_url:
                            existing_name = config_url.split('#')[1]
                        
                        # Check for 'Hamshahri' in the existing name if checkname is True
                        if checkname and "Hamshahri" in existing_name:
                            # Do not rename
                            modified_config = config_url  # Keep original config without renaming
                        else:
                            new_name = generate_hamshahri_name(
                                config_url, 
                                protocol_type, 
                                network_type, 
                                country
                            )
                            
                            if name_suffix:
                                new_name = f"{new_name}-{name_suffix}"
    
                            
                            if '#' in config_url:
                                base_config = config_url.split('#')[0]
                                modified_config = f"{base_config}#{new_name}"
                            else:
                                modified_config = f"{config_url}#{new_name}"
    
                        # If config is working and ping measurement is requested
                        if measure_latency:
                            try:
                                async with aiohttp.ClientSession() as session:
                                    proxy_url = f"socks5://localhost:{port}"
                                    ping = await measure_ping(session, proxy_url)
                                    return {
                                        "config": modified_config,
                                        "status": "success",
                                        "ip": ip_address,
                                        "country": country,
                                        "port": port,
                                        "ping": ping if ping != float('inf') else 999999
                                    }
                            except:
                                # If ping measurement fails, return success with high ping
                                return {
                                    "config": modified_config,
                                    "status": "success",
                                    "ip": ip_address,
                                    "country": country,
                                    "port": port,
                                    "ping": 999999
                                }
                        else:
                            return {
                                "config": modified_config,
                                "status": "success",
                                "ip": ip_address,
                                "country": country,
                                "port": port
                            }
                    
                    except (ValueError, KeyError):
                        return {
                            "config": config_url,
                            "status": "failed",
                            "message": "Invalid JSON response",
                            "port": port
                        }
                else:
                    return {
                        "config": config_url,
                        "status": "failed",
                        "message": "No response from IP check service",
                        "port": port
                    }
            except asyncio.TimeoutError:
                return {
                    "config": config_url,
                    "status": "failed",
                    "message": "Connection timeout",
                    "port": port
                }

        except Exception as e:
            return {
                "config": config_url,
                "status": "error",
                "message": str(e),
                "port": port
            }

    finally:
        if process:
            process.kill()
        if process_curl:
            try:
                process_curl.kill()
            except:
                pass
        if temp_filename and os.path.exists(temp_filename):
            try:
                os.remove(temp_filename)
            except:
                pass

async def test_config_batch(configs: List[str], start_port: int = 1080, batch_size: int = 40, 
                            save_path: str = "working_configs.txt", position: str = "end",
                            sort_by_ping: bool = False, name_suffix: str = None, checkname: bool = False):
    """Test a batch of configs simultaneously and optionally sort by ping"""
    config_counter.reset()
    
    # اضافه کردن خروجی برا check_configs.php
    print(f"Total configs: {len(configs)}")
    
    tasks = []
    for i, config in enumerate(configs):
        port = start_port + (i % batch_size)
        task = asyncio.create_task(test_config(
            config, port, "", position, measure_latency=sort_by_ping, name_suffix=name_suffix, checkname=checkname
        ))
        tasks.append(task)
    
    results = await asyncio.gather(*tasks)
    
    # Filter all successful results
    successful_results = [r for r in results if r["status"] == "success"]
    
    # اضافه کردن خروجی برای check_configs.php
    print(f"Valid configs: {len(successful_results)}")
    
    # Sort by ping if required
    if sort_by_ping:
        successful_results = sorted(successful_results, key=lambda x: float(x.get("ping", 999999)))
    
    # Write configs to file if save_path is provided
    if save_path:
        # Read existing configs if the file exists
        existing_configs = []
        if os.path.exists(save_path):
            with open(save_path, 'r', encoding='utf-8') as f:
                existing_configs = [line.strip() for line in f]
        
        # Extract base configs to avoid duplicates
        existing_base_configs = set()
        for config_line in existing_configs:
            base_config = config_line.split('#')[0]
            existing_base_configs.add(base_config)
        
        # Filter out duplicates from successful_results
        new_configs = []
        for result in successful_results:
            base_config = result['config'].split('#')[0]
            if base_config not in existing_base_configs:
                new_configs.append(result['config'])
                existing_base_configs.add(base_config)  # To prevent duplicates within the same run
            else:
                # Optionally, update the existing config's name if needed
                pass  # You can implement logic here if you want to update existing configs
        
        # Combine configs based on position
        if position == 'start':
            combined_configs = new_configs + existing_configs
        else:
            combined_configs = existing_configs + new_configs
        
        # Sort the combined configs based on priority_key
        combined_configs_sorted = sorted(combined_configs, key=priority_key)
        
        # Write combined and sorted configs back to the file
        with open(save_path, 'w', encoding='utf-8') as f:
            for config in combined_configs_sorted:
                f.write(f"{config}\n")
    
    # Update results with sorted order if sorting was done
    if sort_by_ping:
        failed_results = [r for r in results if r["status"] != "success"]
        results = successful_results + failed_results
    
    return results


def print_results(results: List[Dict], batch_num: int, total_batches: int, show_ping: bool = False):
    """Print test results with formatting"""
    print(f"\nResults for batch {batch_num}/{total_batches}:")
    print("-" * 50)
    
    working_count = sum(1 for r in results if r["status"] == "success")
    new_count = sum(1 for r in results if r["status"] == "success" and not r.get("already_exists", False))
    existing_count = working_count - new_count
    
    print(f"Working configs in this batch: {working_count}/{len(results)}")
    if existing_count > 0:
        print(f"New working configs: {new_count}")
        print(f"Already existing configs: {existing_count}")
    
    for result in results:
        if result["status"] == "success":
            status_str = "\033[92m[SUCCESS]\033[0m"
            if result.get("already_exists", False):
                status_str += " (Already Exists)"
            print(f"\n{status_str} - Port: {result['port']}")
            print(f"IP: {result['ip']}")
            print(f"Country: {result['country']}")
            if show_ping and "ping" in result:
                print(f"Ping: {result['ping']:.1f}ms")
            print(f"Config: {result['config']}")
        else:
            print(f"\n\033[91m[FAILED]\033[0m - Port: {result.get('port', 'N/A')}")
            print(f"Error: {result.get('message', 'Unknown error')}")
            print(f"Config: {result['config']}")
    print("-" * 50)

def read_configs_from_file(file_path: str) -> List[str]:
    """Read configs from a file where each line is a config"""
    try:
        with open(file_path, 'r') as f:
            return [line.strip() for line in f if line.strip()]
    except Exception as e:
        print(f"Error reading file: {str(e)}")
        return []

def remove_inactive_configs(results: List[Dict], filename: str, sort_by_ping: bool = False):
    """Remove inactive configurations from the specified file and save active configs sorted by ping if required."""
    try:
        active_configs = []
        active_base_configs = set()
        for result in results:
            if result["status"] == "success":
                base_config = result["config"].split('#')[0]
                active_base_configs.add(base_config)
                active_configs.append(result)

        if sort_by_ping:
            active_configs.sort(key=lambda x: float(x.get("ping", 999999)))
        
        with open(filename, "w", encoding='utf-8') as f:
            for result in active_configs:
                f.write(f"{result['config']}\n")

        original_count = len(results)
        active_count = len(active_configs)
        removed_count = original_count - active_count

        print(f"\n\033[92m[CLEANUP SUMMARY]\033[0m")
        print(f"Original configs: {original_count}")
        print(f"Active configs: {active_count}")
        print(f"Removed configs: {removed_count}")
        print(f"\033[92m[SUCCESS]\033[0m File cleaned up: {filename}")
        
    except Exception as e:
        print(f"\033[91mError cleaning up file: {str(e)}\033[0m")
        
def filter_configs(configs: List[str], tcp_only: bool) -> List[str]:
    """Filter configs based on protocol and encryption
    
    Args:
        configs: List of config URLs
        tcp_only: If True (-nontcp), only test TCP configs without TLS
        If False (default), test all configs
    """
    filtered_configs = []
    for config in configs:
        if config.startswith("vless://"):
            parsed = parse_vless(config)
        elif config.startswith("vmess://"):
            parsed = parse_vmess(config)
        elif config.startswith("trojan://"):
            parsed = parse_trojan(config)
        elif config.startswith("ss://"):
            parsed = parse_shadowsocks(config)
        else:
            continue
            
        if tcp_only:
            if parsed.network == "tcp" and parsed.security == "none":
                filtered_configs.append(config)
        else:
            filtered_configs.append(config)

    return filtered_configs

def priority_key(config: str) -> int:
    """Assign priority based on config content"""
    if "Manual" in config:
        return 0
    elif "🇮🇷" in config:
        return 1
    elif "VIP-Sv" in config:
        return 2
    else:
        return 3

def create_loadbalancer_config(configs, output_file="loadbalancer.json", name="LoadBalancer-Hamshahri"):
    """
    Create a load balancer config from multiple working configs
    configs: List of JSON configurations
    """
    try:
        loadbalancer_config = {
            "remarks": name,
            "log": {
                "access": "",
                "error": "",
                "loglevel": "warning"
            },
            "inbounds": [
                {
                    "tag": "socks",
                    "port": 10808,
                    "listen": "127.0.0.1",
                    "protocol": "socks",
                    "sniffing": {
                        "enabled": True,
                        "destOverride": ["http", "tls"],
                        "routeOnly": False
                    },
                    "settings": {
                        "auth": "noauth",
                        "udp": True,
                        "allowTransparent": False
                    }
                },
                {
                    "tag": "http",
                    "port": 10809,
                    "listen": "127.0.0.1",
                    "protocol": "http",
                    "sniffing": {
                        "enabled": True,
                        "destOverride": ["http", "tls"],
                        "routeOnly": False
                    },
                    "settings": {
                        "auth": "noauth",
                        "udp": True,
                        "allowTransparent": False
                    }
                }
            ],
            "outbounds": [],
            "routing": {
                "domainStrategy": "IPOnDemand",
                "rules": [],
                "balancers": [
                    {
                        "tag": "balancer",
                        "selector": []
                    }
                ]
            }
        }

        # Process each config
        for i, config in enumerate(configs):
            if not config.strip():
                continue

            try:
                # Handle vmess:// format
                if config.startswith('vmess://'):
                    config_json = json.loads(base64.b64decode(config[8:]).decode('utf-8'))
                else:
                    continue  # Skip non-vmess configs for now

                tag = f"proxy_{i}"
                outbound = {
                    "tag": tag,
                    "protocol": "vmess",
                    "settings": {
                        "vnext": [
                            {
                                "address": config_json.get("add", ""),
                                "port": int(config_json.get("port", 0)),
                                "users": [
                                    {
                                        "id": config_json.get("id", ""),
                                        "alterId": int(config_json.get("aid", 0)),
                                        "security": config_json.get("scy", "auto")
                                    }
                                ]
                            }
                        ]
                    },
                    "streamSettings": {
                        "network": config_json.get("net", "tcp"),
                        "security": config_json.get("tls", "none"),
                        "tlsSettings": {
                            "serverName": config_json.get("sni", "")
                        } if config_json.get("tls") else {},
                        "wsSettings": {
                            "path": config_json.get("path", ""),
                            "headers": {
                                "Host": config_json.get("host", "")
                            }
                        } if config_json.get("net") == "ws" else {}
                    }
                }

                loadbalancer_config["outbounds"].append(outbound)
                loadbalancer_config["routing"]["balancers"][0]["selector"].append(tag)

            except (json.JSONDecodeError, UnicodeDecodeError, KeyError) as e:
                print(f"Error processing config {i}: {str(e)}")
                continue

        # Add balancer outbound
        loadbalancer_config["routing"]["rules"].append({
            "type": "field",
            "balancerTag": "balancer",
            "outboundTag": "direct",
            "network": "tcp,udp"
        })

        # Add direct and blackhole outbounds
        loadbalancer_config["outbounds"].extend([
            {
                "tag": "direct",
                "protocol": "freedom",
                "settings": {}
            },
            {
                "tag": "blackhole",
                "protocol": "blackhole",
                "settings": {}
            }
        ])

        # Verify we have at least one valid outbound
        if not loadbalancer_config["routing"]["balancers"][0]["selector"]:
            raise Exception("No valid configs found for load balancer")

        # Write the config to file
        with open(output_file, 'w') as f:
            json.dump(loadbalancer_config, f, indent=4)

        return True

    except Exception as e:
        print(f"Error creating load balancer config: {str(e)}")
        return False

def get_country_emoji(country_str: str) -> str:
    """Get country emoji from country string, handling special cases"""
    # Remove any extra information in parentheses
    country = country_str.split('(')[0].strip()
    
    # Handle special cases
    country_mapping = {
        "Sofia": "Bulgaria",
        "Moscow": "Russia",
        "Sankt-Peterburg": "Russia",
        "Beijing": "China",
        "Shanghai": "China",
        # Add more city-to-country mappings as needed
    }
    
    # Try to map city to country
    if country in country_mapping:
        country = country_mapping[country]
    
    # Get emoji from COUNTRY_EMOJIS
    return COUNTRY_EMOJIS.get(country, COUNTRY_EMOJIS["Unknown"])

async def main():
    parser = argparse.ArgumentParser(description='Test V2Ray configurations')
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument('-config', help='Subscription link or single config')
    group.add_argument('-file', help='Path to file containing configs (one per line)')
    
    parser.add_argument('-port', type=int, default=1080, help='Starting port number (default: 1080)')
    parser.add_argument('-batch', type=int, default=40, help='Batch size for testing configs (default: 40)')
    parser.add_argument('-save', type=str, default='/root/working_configs.txt', help='Path to save successful configs')
    parser.add_argument('-nontcp', action='store_true', help='Test only non-TCP configurations')
    parser.add_argument('-position', choices=['start', 'end'], default='end', 
                       help='Position to add configs: start or end (default: end)')
    parser.add_argument('-remove', action='store_true', help='Remove inactive configs from input file')
    parser.add_argument('-sort', choices=['ping'], help='Sort configs by ping time')
    parser.add_argument('-name', type=str, help='Append a custom name to the config names')
    parser.add_argument('-checkname', action='store_true', help='Check if config names contain "Hamshahri" and skip renaming if they do')
    parser.add_argument('-loadbalancer', action='store_true', help='Create load balancer config from working configs')
    parser.add_argument('-lb-output', default='loadbalancer.json', help='Output file for load balancer config')
    parser.add_argument('-lb-name', default='LoadBalancer-Hamshahri', help='Name for load balancer config')
    parser.add_argument('-nocheck', action='store_true', help='Skip testing configs when creating loadbalancer')
    parser.add_argument('-count', type=int, help='Number of configs to use (limits the configs processed)')

    args = parser.parse_args()
    
    try:
        configs = []
        input_file = None
        
        if args.config:
            if args.config.startswith("http"):
                print("Fetching subscription configs...")
                configs = fetch_subscription(args.config)
            else:
                configs = [args.config]
        elif args.file:
            input_file = args.file
            configs = read_configs_from_file(args.file)

        if not configs:
            print("No valid configs found!")
            return

        # Limit number of configs if count is specified
        if args.count and args.count > 0:
            original_count = len(configs)
            configs = configs[:args.count]
            print(f"\nLimiting configs to first {len(configs)} of {original_count} configs")

        # If -nocheck is used with -loadbalancer, skip testing and create loadbalancer directly
        if args.loadbalancer and args.nocheck:
            print("\nCreating load balancer configuration without testing configs...")
            successful_configs = []
            for config in configs:
                try:
                    config_json = config_to_json(config)
                    if "error" not in config_json:
                        successful_configs.append(config_json)
                except Exception as e:
                    print(f"Error processing config: {str(e)}")
                    continue
            
            if successful_configs:
                create_loadbalancer_config(successful_configs, args.lb_output, args.lb_name)
                print(f"Load balancer configuration saved to: {args.lb_output}")
            else:
                print("No valid configs found for load balancer")
            return

        tcp_only = args.nontcp
        configs = filter_configs(configs, tcp_only=tcp_only)
        
        if not configs:
            print("No valid configurations found after filtering!")
            return

        print(f"\nUsing settings:")
        print(f"Starting port: {args.port}")
        print(f"Batch size: {args.batch}")
        if not args.remove:
            print(f"Saving successful configs to: {args.save}")
        print(f"Testing {'only TCP without TLS' if args.nontcp else 'all'} configurations")
        if args.remove:
            print("Remove mode: Will clean up inactive configs from input file")
        if args.sort:
            print(f"Sorting configs by: {args.sort}")

        total_batches = (len(configs) + args.batch - 1) // args.batch
        all_results = []

        for batch_num in range(total_batches):
            start_idx = batch_num * args.batch
            end_idx = min(start_idx + args.batch, len(configs))
            current_batch = configs[start_idx:end_idx]
            
            print(f"\nTesting batch {batch_num + 1}/{total_batches} ({len(current_batch)} configs)")
            
            results = await test_config_batch(
                current_batch,
                args.port,
                args.batch,
                None if args.remove else args.save,
                args.position,
                sort_by_ping=bool(args.sort == 'ping'),
                name_suffix=args.name,
                checkname=args.checkname
            )
            
            print_results(results, batch_num + 1, total_batches, show_ping=bool(args.sort == 'ping'))
            all_results.extend(results)

        if args.remove and input_file:
            remove_inactive_configs(all_results, input_file, sort_by_ping=bool(args.sort == 'ping'))
        else:
            # Read existing configs if file exists
            existing_configs = []
            existing_base_configs = set()
            if os.path.exists(args.save):
                with open(args.save, 'r', encoding='utf-8') as f:
                    for line in f:
                        line = line.strip()
                        if line:
                            # Store base config for duplicate checking
                            base_config = line.split('#')[0]
                            if base_config not in existing_base_configs:
                                existing_configs.append(line)
                                existing_base_configs.add(base_config)

            # Process new successful configs
            successful_configs = []
            for result in all_results:
                if result["status"] == "success":
                    config = result['config']
                    base_config = config.split('#')[0]
                    if base_config not in existing_base_configs:
                        successful_configs.append(config)
                        existing_base_configs.add(base_config)

            # Combine existing and new configs
            combined_configs = []
            if args.position == 'start':
                combined_configs = successful_configs + existing_configs
            else:  # 'end'
                combined_configs = existing_configs + successful_configs

            # Sort all configs based on priority
            sorted_configs = sorted(combined_configs, key=priority_key)

            # Write all configs back to file
            with open(args.save, 'w', encoding='utf-8') as f:
                for config in sorted_configs:
                    f.write(f"{config}\n")
            
            total_new = len(successful_configs)
            total_existing = len(existing_configs)
            total_saved = len(sorted_configs)
            
            print(f"\nFinal Summary:")
            print(f"Total configs tested: {len(all_results)}")
            print(f"New working configs found: {total_new}")
            print(f"Existing configs preserved: {total_existing}")
            print(f"Total configs saved: {total_saved}")
            print(f"Success rate for new configs: {(total_new / len(all_results) * 100):.1f}%")
            print(f"Updated working configs file: {args.save}")

            # After printing the final summary
            if args.loadbalancer:
                print("\nCreating load balancer configuration...")
                successful_configs = []
                for result in all_results:
                    if result["status"] == "success":
                        config_json = config_to_json(result["config"])
                        successful_configs.append(config_json)
                
                if successful_configs:
                    create_loadbalancer_config(successful_configs, args.lb_output, args.lb_name)
                    print(f"Load balancer configuration saved to: {args.lb_output}")
                else:
                    print("No working configs found for load balancer")

    except KeyboardInterrupt:
        print("\nOperation cancelled by user.")
    except Exception as e:
        print(f"\nUnexpected error: {str(e)}")

if __name__ == "__main__":
    asyncio.run(main())
