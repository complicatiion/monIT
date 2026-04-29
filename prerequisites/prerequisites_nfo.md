# prerequisite NFO

# Setup Instructions Prerequisite: PHP & Net-SNMP & vcredist

##Note: packages.zip already below "Pakcages" dowloadable from monIT Packages Repo

This guide provides instructions for installing PHP 8.5 and the required Net-SNMP binaries for the monIT environment.

## 1. PHP Installation (VS17 x64 Non Thread Safe)

### Prerequisites
- **Source:** [Official PHP Downloads for Windows](https://www.php.net/downloads.php?os=windows)
- **Version:** PHP 8.5.5 (Zip)
- **File Integrity (SHA256):** `107f64f689eec2a0966b4d8a42f0e34e8dfa04c5097c9548e35fb951cba0a464`

### Deployment
1. Download the Zip package (approx. 33.74 MB).
2. Extract the contents.
3. Rename the extracted folder to `php`.
4. Move the folder directly to the root directory `C:\`.
   - **Target Path:** `C:\php\` (The PHP executable files must be located directly within this folder).

---

## 2. Net-SNMP Binaries

### Prerequisites
- **Source:** [Net-SNMP on SourceForge](https://sourceforge.net/projects/net-snmp/)
- **Version:** Tested with v5.5.0 (higher versions are compatible).

### Deployment
The following binaries must be extracted and moved to the application's binary directory:

1. Locate `snmpget.exe` and `snmpwalk.exe`.
2. Copy both files to the `monIT\bin\` subdirectory.
   - **Example Path:** `...\monIT\bin\snmpget.exe`
   - **Example Path:** `...\monIT\bin\snmpwalk.exe`

---

## 2. Visual C++ Redistributable 2015–2022 (if nto already installed on System)

### Prerequisites
Visual C++ Redistributable x64 or x86 (x64 recommended)

- **Source x64:** https://aka.ms/vs/17/release/vc_redist.x64.exe
- **Source x86:** https://aka.ms/vs/17/release/vc_redist.x86.exe



---

## Technical Support
Ensure that the target paths are correctly configured and that the user has sufficient permissions to write to `C:\`.

---

complicatiion 28.04.2026

---

