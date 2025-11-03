<?php

if (!defined('NEITCUS_EXEC')) {
    header('HTTP/1.0 403 Forbidden');
    die('Accesso diretto negato');
}

// Configurazione del database (queste rimangono hardcoded)
define('DB_HOST', 'localhost'); // Posizione DB <<== CONFIGURARE
define('DB_NAME', 'w360italymtcom_neitcusstaging'); // Nome DB <<== CONFIGURARE
define('DB_USER', 'w360italymtcom_neitasync'); // Utente DB <<== CONFIGURARE
define('DB_PASS', 'lS=YW3wpxv9l7CBS'); // Password DB <<== CONFIGURARE

// ═══════════════════════════════════════════════════════════════
// INFORMAZIONI APPLICAZIONE
// ═══════════════════════════════════════════════════════════════
define('APP_NAME', 'NEITCUS® Business Intelligence | Enterprise');
define('APP_VERSION', '2.0.6');
define('NUMERO_UTENTI', 10);

// ═══════════════════════════════════════════════════════════════
// URL BASE CLIENTE
// ═══════════════════════════════════════════════════════════════
define('BASE_URL', 'https://app.neitcus.com/neitcus_20/'); // <<== CONFIGURARE

// ═══════════════════════════════════════════════════════════════
// PATH CONDIVISI - FILESYSTEM (per require/include PHP)
// ═══════════════════════════════════════════════════════════════
define('SHARED_FUNCTIONS_PATH', '/home/w360italymtcom/app.neitcus.com/neitcus_v2_staging/functions/'); // <<== CONFIGURARE
define('SHARED_LIBRARIES_PATH', '/home/w360italymtcom/app.neitcus.com/neitcus_v2_staging/libraries/'); // <<== CONFIGURARE
define('SHARED_TEMPLATES_PATH', '/home/w360italymtcom/app.neitcus.com/neitcus_v2_staging/templates/'); // <<== CONFIGURARE
define('SHARED_ASSETS_PATH', '/home/w360italymtcom/app.neitcus.com/neitcus_v2_staging/assets/'); // <<== CONFIGURARE

// ═══════════════════════════════════════════════════════════════
// URL CONDIVISI - WEB (per browser: CSS, JS, IMG)
// ═══════════════════════════════════════════════════════════════
define('ASSETS_URL', 'https://app.neitcus.com/neitcus_v2_staging/assets/'); // <<== CONFIGURARE

// ═══════════════════════════════════════════════════════════════
// PATH LOCALI CLIENTE (file specifici del cliente)
// ═══════════════════════════════════════════════════════════════
define('ROOT_PATH', dirname(__FILE__));
define('UPLOAD_PATH', ROOT_PATH . '/uploads/');
define('REPORTS_PATH', ROOT_PATH . '/reports/');
define('LOGS_PATH', ROOT_PATH . '/logs/');
define('TMP_PATH', ROOT_PATH . '/tmp/');

// Configurazione errori e debug (fisse)
define('DEBUG_MODE', false); // Impostare a false in produzione
ini_set('display_errors', DEBUG_MODE ? 1 : 0);
error_reporting(E_ALL);

// =====================================================
// CONFIGURAZIONI EMAIL SMTP
// =====================================================
define('EMAIL_ENABLED', true);
define('EMAIL_FROM_ADDRESS', 'admin@neitcus.com');
define('EMAIL_FROM_NAME', 'NEITCUS Sistema');

// Configurazioni SMTP
define('SMTP_ENABLED', true);
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_ENCRYPTION', 'TLS'); // tls, ssl, none
define('SMTP_USERNAME', 'neitcus25@gmail.com'); // ← CAMBIA QUESTO
define('SMTP_PASSWORD', 'cxct ltnf dimr umld'); // ← CAMBIA QUESTO
define('SMTP_TIMEOUT', 30);

// Opzioni email
define('EMAIL_LOG_ENABLED', true);
define('EMAIL_DEBUG_MODE', false);
define('EMAIL_MAX_RETRY', 3);

// =====================================================
// CARICAMENTO CONFIGURAZIONI DAL DATABASE
// =====================================================

/**
 * Carica le configurazioni dal database e le setta come costanti PHP
 */
function loadConfigurationsFromDB() {
    try {
        // Connessione diretta al database per il caricamento config
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', 
            DB_USER, 
            DB_PASS, 
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        
        // Query per recuperare tutte le configurazioni
        $stmt = $pdo->query("SELECT chiave, valore, tipo FROM configurazioni_sistema ORDER BY chiave");
        $configurations = $stmt->fetchAll();
        
        // Processa ogni configurazione e crea le costanti
        foreach ($configurations as $config) {
            $key = $config['chiave'];
            $value = $config['valore'];
            $type = $config['tipo'];
            
            // Converte il valore secondo il tipo
            switch ($type) {
                case 'boolean':
                    $value = ($value === 'true' || $value === '1' || $value === 1);
                    break;
                case 'integer':
                    $value = (int)$value;
                    break;
                case 'string':
                case 'text':
                default:
                    $value = (string)$value;
                    break;
            }
            
            // Definisce la costante se non esiste già
            if (!defined($key)) {
                define($key, $value);
            }
        }
        
        return true;
        
    } catch (PDOException $e) {
        // In caso di errore DB, logga l'errore
        error_log("Errore caricamento configurazioni DB: " . $e->getMessage());
        
        // Non definire fallback - lascia che l'app gestisca la mancanza di configurazioni
        return false;
    }
}

// Carica le configurazioni dal database
loadConfigurationsFromDB();

// =====================================================
// FUNZIONI HELPER (INVARIATE)
// =====================================================

// Funzione per la connessione al database
function getDbConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    // Verifica della connessione
    if ($conn->connect_error) {
        die("Connessione fallita: " . $conn->connect_error);
    }
    
    // Imposta il charset a utf8mb4
    $conn->set_charset("utf8mb4");
    
    return $conn;
}

// Funzione per sanitizzare gli input
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Funzione per registrare i log
function logActivity($message, $type = 'info') {
    $logFile = ROOT_PATH . '/logs/activity_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$type] $message" . PHP_EOL;
    
    // Crea la directory dei log se non esiste
    if (!file_exists(dirname($logFile))) {
        mkdir(dirname($logFile), 0755, true);
    }
    
    // Scrivi nel file di log
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// =====================================================
// FUNZIONI PER GESTIONE CONFIGURAZIONI
// =====================================================

/**
 * Ottiene una configurazione dal database
 * @param string $key Chiave della configurazione
 * @param mixed $default Valore di default se non trovata
 * @return mixed
 */
function getConfig($key, $default = null) {
    if (defined($key)) {
        return constant($key);
    }
    return $default;
}

/**
 * Aggiorna una configurazione nel database
 * @param string $key Chiave della configurazione
 * @param mixed $value Nuovo valore
 * @param string $type Tipo del valore (string, integer, boolean, text)
 * @return bool
 */
function updateConfig($key, $value, $type = 'string') {
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', 
            DB_USER, 
            DB_PASS, 
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Converte il valore per il database
        if ($type === 'boolean') {
            $value = $value ? 'true' : 'false';
        } else {
            $value = (string)$value;
        }
        
        // Query di upsert (inserisce o aggiorna)
        $stmt = $pdo->prepare("
            INSERT INTO configurazioni_sistema (chiave, valore, tipo, updated_at) 
            VALUES (:key, :value, :type, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE 
            valore = VALUES(valore), 
            tipo = VALUES(tipo),
            updated_at = CURRENT_TIMESTAMP
        ");
        
        $stmt->execute([
            ':key' => $key,
            ':value' => $value,
            ':type' => $type
        ]);
        
        return true;
        
    } catch (PDOException $e) {
        error_log("Errore aggiornamento configurazione $key: " . $e->getMessage());
        return false;
    }
}

/**
 * Verifica se le configurazioni OBBLIGATORIE sono presenti
 * OPENAI_ANALYSIS_ENABLED è OPZIONALE (può essere true/false)
 * @return array Array con 'complete' (bool) e 'missing' (array di chiavi mancanti)
 */
function checkConfigurationsStatus() {
    $required_configs = [
        'COMPANY_NAME',
        'NUMERO_DIPENDENTI',
        'OPENAI_MODEL',
        'OPENAI_API_KEY',
        'AZIENDA_CONTESTO',
        'AZIENDA_SETTORI',
        'AZIENDA_TIPOLOGIA',
        'AZIENDA_TONO'
    ];
    
    $missing = [];
    foreach ($required_configs as $config) {
        if (!defined($config)) {
            $missing[] = $config;
        }
    }
    
    return [
        'complete' => empty($missing),
        'missing' => $missing,
        'total' => count($required_configs),
        'found' => count($required_configs) - count($missing)
    ];
}

/**
 * Ottiene tutte le configurazioni dal database
 * @return array
 */
function getAllConfigurations() {
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', 
            DB_USER, 
            DB_PASS, 
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $stmt = $pdo->query("
            SELECT chiave, valore, tipo, categoria, descrizione, is_sensitive, updated_at 
            FROM configurazioni_sistema 
            ORDER BY categoria, chiave
        ");
        
        return $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Errore lettura configurazioni: " . $e->getMessage());
        return [];
    }
}
?>