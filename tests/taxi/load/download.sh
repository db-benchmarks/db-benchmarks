#!/bin/bash

# Define the directory to store downloaded files
DATA_DIR="../data"
URL_FILE="list.txt"

# Ensure the data directory exists
mkdir -p "$DATA_DIR"

# Define color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Get total number of URLs to track progress
total_urls=$(wc -l < "$URL_FILE")
current_count=0

# Read each URL from the file
while IFS= read -r url; do
    ((current_count++))
    # Extract the filename from the URL
    filename=$(basename "$url")
    file_path="$DATA_DIR/$filename"

    # Check if the file already exists
    if [ -f "$file_path" ]; then
        echo -e "${YELLOW}[$current_count/$total_urls] File $filename already exists, skipping download.${NC}"
    elif [ -f "$file_path.gz" ]; then
        echo -e "${YELLOW}[$current_count/$total_urls] File $filename has already been downloaded but not yet unarchived. Starting the unarchiving process.${NC}"
        gunzip "$file_path.gz"
    else
        sleep 5
        echo -e "${YELLOW}[$current_count/$total_urls] Downloading $filename...${NC}"

        # Download file with progress bar and capture HTTP status code
        status_code=$(curl --progress-bar -o "$file_path.gz" -w "%{http_code}" "$url.gz")

        # Check HTTP response status
        if [ "$status_code" -eq 200 ]; then
            echo -e "${GREEN}Download successful: $filename${NC}"
            echo "Start unarchiving"
            gunzip "$file_path.gz"
            echo -e "${GREEN}Unarchiving was successful"
        else
            echo -e "${RED}Download failed for $filename (HTTP Status: $status_code)${NC}"
            rm -f "$file_path"  # Remove incomplete file if failed
        fi
    fi
done < "$URL_FILE"

echo -e "${GREEN}Download process completed.${NC}"