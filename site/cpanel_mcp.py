import os
import shutil
import requests
from dotenv import load_dotenv
from mcp.server.fastmcp import FastMCP

# Load configuration from .env file
load_dotenv()

API_TOKEN = os.getenv("CPANEL_API_TOKEN")
USER = os.getenv("CPANEL_USER")
HOST = os.getenv("CPANEL_HOST") # e.g., yourserver.com:2083
REMOTE_DIR = os.getenv("CPANEL_REMOTE_DIR", "/public_html")

mcp = FastMCP("cPanel-Deployer")

# Headers for cPanel API Authentication
headers = {
    "Authorization": f"cpanel {USER}:{API_TOKEN}"
}

@mcp.tool()
def get_disk_usage():
    """Checks the available disk space on the cPanel hosting."""
    if not all([API_TOKEN, USER, HOST]):
        return {"error": "Missing configuration in .env. Please provide CPANEL_API_TOKEN, CPANEL_USER, and CPANEL_HOST."}
    
    url = f"https://{HOST}/execute/Quota/get_quota_info"
    try:
        response = requests.get(url, headers=headers)
        response.raise_for_status()
        return response.json()
    except Exception as e:
        return {"error": str(e)}

@mcp.tool()
def upload_build(folder_path: str):
    """Zips, uploads, and extracts a folder to the cPanel directory."""
    if not all([API_TOKEN, USER, HOST]):
        return "Error: Missing configuration in .env."
        
    if not os.path.exists(folder_path):
        return f"Error: Folder path '{folder_path}' does not exist."

    # Step 1: Zip the folder
    zip_base_name = f"{os.path.basename(folder_path)}_build"
    archive_path = shutil.make_archive(zip_base_name, 'zip', folder_path)
    zip_filename = os.path.basename(archive_path)

    try:
        # Step 2: Upload to cPanel via Fileman::upload_files
        upload_url = f"https://{HOST}/execute/Fileman/upload_files"
        with open(archive_path, 'rb') as f:
            files = {
                'file-1': (zip_filename, f, 'application/zip')
            }
            data = {'dir': REMOTE_DIR}
            upload_res = requests.post(upload_url, headers=headers, files=files, data=data)
            upload_res.raise_for_status()
        
        # Step 3: Extract the zip on the server
        extract_url = f"https://{HOST}/execute/Fileman/extract"
        extract_res = requests.post(extract_url, headers=headers, data={
            'dir': REMOTE_DIR,
            'file': zip_filename,
            'overwrite': 1
        })
        extract_res.raise_for_status()

        # Step 4: Cleanup (Local zip)
        os.remove(archive_path)
        
        return {
            "status": "success",
            "message": f"Successfully uploaded and extracted '{folder_path}' to {REMOTE_DIR}",
            "upload_details": upload_res.json(),
            "extract_details": extract_res.json()
        }

    except Exception as e:
        if os.path.exists(archive_path):
            os.remove(archive_path)
        return {"error": str(e)}

if __name__ == "__main__":
    mcp.run()
