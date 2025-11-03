<?php
/**
 * Functions Giornale - Funzioni specializzate per il calcolo di metriche finanziarie 
 * basate sulla tabella libro_giornale
 */

// Controlla accesso diretto
if (!defined('NEITCUS_EXEC')) {
    die('Accesso diretto non consentito');
}


require_once SHARED_FUNCTIONS_PATH . 'configurazione_conti.php';

function get_trend_mensili($db, $anno) {
    // Validazione input
    if (!is_numeric($anno) || $anno < 1900 || $anno > 2100) {
        error_log("Anno non valido: $anno");
        return [];
    }
    
    $trend = [];
    
    try {
        for ($i = 1; $i <= 12; $i++) {
            $mese = str_pad($i, 2, '0', STR_PAD_LEFT);
            $data_inizio = "$anno-$mese-01";
            
            $ultimo_giorno = date('t', strtotime($data_inizio));
            $data_fine = "$anno-$mese-$ultimo_giorno";
            
            $ricavi = calcola_ricavi_totali_giornale($db, $data_inizio, $data_fine);
            $costi = calcola_costi_totali_giornale($db, $data_inizio, $data_fine);
            
            $trend[] = [
                'mese' => $mese,
                'nome_mese' => date('F', strtotime($data_inizio)),
                'ricavi' => round($ricavi, 2),
                'costi' => round($costi, 2),
                'margine' => round($ricavi - $costi, 2)
            ];
        }
        
        return $trend;
    } catch (Exception $e) {
        error_log("Errore in get_trend_mensili: " . $e->getMessage());
        return [];
    }
}

/**
 * Calcola i costi diretti utilizzando il flag natura_costo
 * Include TUTTI i conti mappati con natura_costo = 'diretto'
 * indipendentemente dalla categoria di appartenenza
 * 
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float Totale dei costi diretti nel periodo
 */
function calcola_costi_diretti_giornale($db, $data_inizio, $data_fine) {
    
    // Usa la nuova logica basata su natura_costo
    $patterns_costi_diretti = getContiByNaturaCosto($db, 'diretto');
    
    // Se non ci sono pattern, ritorna 0
    if (empty($patterns_costi_diretti)) {
        return 0;
    }
    
    $where_costi_diretti = buildWhereClauseConti($patterns_costi_diretti);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_costi_diretti THEN dare - avere ELSE 0 END), 0) AS totale_costi_diretti
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $totale_costi = $result['totale_costi_diretti'];
        
        return abs($totale_costi); // Assicurati che sia positivo
        
    } catch (PDOException $e) {
        error_log("Errore nel calcolo costi diretti da giornale: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola i costi indiretti utilizzando il flag natura_costo
 * Include TUTTI i conti mappati con natura_costo = 'indiretto'
 * indipendentemente dalla categoria di appartenenza
 * 
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float Totale dei costi indiretti nel periodo
 */
function calcola_costi_indiretti_giornale($db, $data_inizio, $data_fine) {
    
    // Usa la nuova logica basata su natura_costo
    $patterns_costi_indiretti = getContiByNaturaCosto($db, 'indiretto');
    
    // Se non ci sono pattern, ritorna 0
    if (empty($patterns_costi_indiretti)) {
        return 0;
    }
    
    $where_costi_indiretti = buildWhereClauseConti($patterns_costi_indiretti);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_costi_indiretti THEN dare - avere ELSE 0 END), 0) AS totale_costi_indiretti
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $totale_costi_indiretti = $result['totale_costi_indiretti'];
        
        return abs($totale_costi_indiretti); // Assicurati che sia positivo
        
    } catch (PDOException $e) {
        error_log("Errore nel calcolo costi indiretti da giornale: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola i costi operativi (per EBITDA) utilizzando il nuovo sistema natura_costo
 * Include: Costi diretti + indiretti (da natura_costo) + Personale (categoria dedicata)
 * ESCLUDE: Ammortamenti, Svalutazioni, Minusvalenze, Imposte, Oneri Finanziari
 * 
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float Totale dei costi operativi nel periodo
 */
function calcola_costi_operativi_giornale($db, $data_inizio, $data_fine) {
    // Usa il nuovo sistema natura_costo per diretti e indiretti
    $patterns_diretti = getContiByNaturaCosto($db, 'diretto');
    $patterns_indiretti = getContiByNaturaCosto($db, 'indiretto');
    
    // Categorie con funzioni dedicate (non classificabili come diretto/indiretto)
    $patterns_personale = getContiByCategoria($db, 'COSTI_PERSONALE');
    $patterns_oneri_sociali = getContiByCategoria($db, 'ONERI_SOCIALI');
    
    // Combina TUTTI i costi operativi
    $tutti_patterns_costi_operativi = array_merge(
        $patterns_diretti,
        $patterns_indiretti,
        $patterns_personale,
        $patterns_oneri_sociali
    );
    
    if (empty($tutti_patterns_costi_operativi)) {
        return 0;
    }
    
    $where_costi = buildWhereClauseConti($tutti_patterns_costi_operativi);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_costi THEN dare - avere ELSE 0 END), 0) AS totale_costi_operativi
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $totale_costi_operativi = abs($result['totale_costi_operativi'] ?? 0);
        
        return $totale_costi_operativi;
    } catch (PDOException $e) {
        error_log("Errore nel calcolo costi operativi da giornale: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola i ricavi da prestazioni e servizi utilizzando i dati del libro giornale
 * Seleziona tutti i conti che iniziano con '5810'
 * 
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float Totale dei ricavi da prestazioni nel periodo
 */
function ricavi_prestazioni_giornale($db, $data_inizio, $data_fine) {
   
    // Usa la nuova logica per ottenere i ricavi prestazioni
    $patterns_ricavi_prestazioni = getContiByCategoria($db, 'RICAVI_PRESTAZIONI');
    
    // Genera dinamicamente la WHERE clause basata sui pattern configurati
    $where_prestazioni = buildWhereClauseConti($patterns_ricavi_prestazioni);
    
    $query = "SELECT 
        COALESCE(SUM(CASE WHEN $where_prestazioni THEN avere ELSE 0 END), 0) as totale_avere,
        COALESCE(SUM(CASE WHEN $where_prestazioni THEN dare ELSE 0 END), 0) as totale_dare
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
        
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // I ricavi sono solitamente registrati in AVERE, ma possono esserci stornati in DARE
        // Per i conti di ricavo, il saldo è: AVERE - DARE
        $totale_ricavi = abs($result['totale_avere'] - $result['totale_dare']);
        
        return $totale_ricavi;
    } catch (PDOException $e) {
        // Log dell'errore
        error_log("Errore nel calcolo ricavi prestazioni da giornale: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola i ricavi da corrispettivi utilizzando i dati del libro giornale
 * Seleziona il conto specifico '5805100'
 * 
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float Totale dei ricavi da corrispettivi nel periodo
 */
function ricavi_corrispettivi_giornale($db, $data_inizio, $data_fine) {
    
    // Usa la nuova logica per ottenere i ricavi corrispettivi
    $patterns_ricavi_corrispettivi = getContiByCategoria($db, 'RICAVI_CORRISPETTIVI');
    
    // Genera dinamicamente la WHERE clause basata sui pattern configurati
    $where_corrispettivi = buildWhereClauseConti($patterns_ricavi_corrispettivi);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_corrispettivi THEN avere ELSE 0 END), 0) as totale_avere,
            COALESCE(SUM(CASE WHEN $where_corrispettivi THEN dare ELSE 0 END), 0) as totale_dare
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // I ricavi sono solitamente registrati in AVERE, ma possono esserci stornati in DARE
        // Per i conti di ricavo, il saldo è: AVERE - DARE
        $totale_ricavi = abs($result['totale_avere'] - $result['totale_dare']);
        
        return $totale_ricavi;
    } catch (PDOException $e) {
        // Log dell'errore
        error_log("Errore nel calcolo ricavi corrispettivi da giornale: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola i ricavi da vendita prodotti utilizzando i dati del libro giornale
 * Seleziona i conti specifici: 5805504, 5805510, 5805522, 5805550, 5805556
 * 
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float Totale dei ricavi da vendita prodotti nel periodo
 */
function ricavi_prodotti_giornale($db, $data_inizio, $data_fine) {
    
    // Usa la nuova logica per ottenere i ricavi vendite (che includono i prodotti)
    $patterns_ricavi_vendite = getContiByCategoria($db, 'RICAVI_VENDITE');
    
    // Genera dinamicamente la WHERE clause basata sui pattern configurati
    $where_prodotti = buildWhereClauseConti($patterns_ricavi_vendite);
    
    $query = "SELECT 
        COALESCE(SUM(CASE WHEN $where_prodotti THEN avere ELSE 0 END), 0) as totale_avere,
        COALESCE(SUM(CASE WHEN $where_prodotti THEN dare ELSE 0 END), 0) as totale_dare
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // I ricavi sono solitamente registrati in AVERE, ma possono esserci stornati in DARE
        // Per i conti di ricavo, il saldo è: AVERE - DARE
        $totale_ricavi = abs($result['totale_avere'] - $result['totale_dare']);
        
        return $totale_ricavi;
    } catch (PDOException $e) {
        // Log dell'errore
        error_log("Errore nel calcolo ricavi prodotti da giornale: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola gli acquisti da fornitori per dashboard Partners (usato per DPO)
 * Include TUTTI i costi diretti e indiretti (acquisti esterni)
 * ESCLUDE: Personale, Ammortamenti, Imposte, Oneri finanziari
 * 
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float Totale acquisti da fornitori nel periodo
 */
function calcola_acquisti_da_fornitori($db, $data_inizio, $data_fine) {
    // Usa il nuovo sistema natura_costo
    // Acquisti = tutti i costi diretti + indiretti (esclude personale, imposte, finanziari)
    $patterns_diretti = getContiByNaturaCosto($db, 'diretto');
    $patterns_indiretti = getContiByNaturaCosto($db, 'indiretto');
    
    // Combina tutti i pattern di acquisti
    $tutti_patterns_acquisti = array_merge(
        $patterns_diretti,
        $patterns_indiretti
    );
    
    if (empty($tutti_patterns_acquisti)) {
        return 0;
    }
    
    $where_acquisti = buildWhereClauseConti($tutti_patterns_acquisti);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_acquisti THEN dare - avere ELSE 0 END), 0) AS totale_acquisti
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return abs($result['totale_acquisti'] ?? 0);
        
    } catch (PDOException $e) {
        error_log("Errore calcolo acquisti fornitori: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola i ricavi totali utilizzando i dati del libro giornale
 * 
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float Totale dei ricavi nel periodo
 */
function calcola_ricavi_totali_giornale($db, $data_inizio, $data_fine) {
    // Ricavi operativi core
    $patterns_ricavi_vendite = getContiByCategoria($db, 'RICAVI_VENDITE');
    $patterns_ricavi_prestazioni = getContiByCategoria($db, 'RICAVI_PRESTAZIONI');
    $patterns_ricavi_corrispettivi = getContiByCategoria($db, 'RICAVI_CORRISPETTIVI');
    
    // Plusvalenze e sopravvenienze attive (componenti straordinari positivi)
    $patterns_plusvalenze_cessioni = getContiByCategoria($db, 'PLUSVALENZE_CESSIONI');
    $patterns_altre_plusvalenze = getContiByCategoria($db, 'ALTRE_PLUSVALENZE');
    $patterns_sopravvenienze_attive = getContiByCategoria($db, 'SOPRAVVENIENZE_ATTIVE');
    
    // Combina TUTTI i pattern di ricavo
    $tutti_patterns_ricavi = array_merge(
        $patterns_ricavi_vendite,
        $patterns_ricavi_prestazioni,
        $patterns_ricavi_corrispettivi,
        $patterns_plusvalenze_cessioni,
        $patterns_altre_plusvalenze,
        $patterns_sopravvenienze_attive
    );
    
    // Genera dinamicamente la WHERE clause basata sui pattern configurati
    $where_ricavi = buildWhereClauseConti($tutti_patterns_ricavi);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_ricavi THEN avere - dare ELSE 0 END), 0) AS totale_ricavi
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $totale_ricavi = abs($result['totale_ricavi'] ?? 0); // Assicuriamo valore positivo
        
        return $totale_ricavi;
    } catch (PDOException $e) {
        // Log dell'errore
        error_log("Errore nel calcolo ricavi totali da giornale: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola i costi totali utilizzando i dati del libro giornale
 * VERSIONE CORRETTA: include TUTTE le categorie di costo per MARGINE NETTO
 */
function calcola_costi_totali_giornale($db, $data_inizio, $data_fine) {
    // Costi operativi
    $patterns_costi_diretti = getContiByCategoria($db, 'COSTI_DIRETTI');
    $patterns_costi_indiretti = getContiByCategoria($db, 'COSTI_INDIRETTI');
    $patterns_costi_materie = getContiByCategoria($db, 'COSTI_MATERIE_PRIME');
    $patterns_costi_lavorazioni = getContiByCategoria($db, 'COSTI_LAVORAZIONI');
    $patterns_costi_servizi = getContiByCategoria($db, 'COSTI_SERVIZI');
    $patterns_costi_personale = getContiByCategoria($db, 'COSTI_PERSONALE');
    
    $patterns_it_software = getContiByCategoria($db, 'COSTI_IT_SOFTWARE');
    $patterns_marketing = getContiByCategoria($db, 'COSTI_MARKETING');
    $patterns_affitti_utenze = getContiByCategoria($db, 'COSTI_AFFITTI_UTENZE');
    
    // Nuove categorie operative
    $patterns_oneri_sociali = getContiByCategoria($db, 'ONERI_SOCIALI');
    $patterns_oneri_vari = getContiByCategoria($db, 'ONERI_VARI');
    
    // Costi non operativi
    $patterns_ammortamenti = getContiByCategoria($db, 'AMMORTAMENTI');
    $patterns_svalutazioni = getContiByCategoria($db, 'SVALUTAZIONI');
    $patterns_minusvalenze = getContiByCategoria($db, 'MINUSVALENZE_CESSIONI');
    
    // Nuove categorie straordinarie
    $patterns_altre_minusvalenze = getContiByCategoria($db, 'ALTRE_MINUSVALENZE');
    $patterns_sopravvenienze_passive = getContiByCategoria($db, 'SOPRAVVENIENZE_PASSIVE');
    
    // Imposte e oneri finanziari
    $patterns_imposte = getContiByCategoria($db, 'IMPOSTE_TASSE');
    $patterns_oneri_finanziari = getContiByCategoria($db, 'ONERI_FINANZIARI');
    
    // Combina TUTTI i pattern di costo
    $tutti_patterns_costi = array_merge(
        $patterns_costi_diretti,
        $patterns_costi_indiretti,
        $patterns_costi_materie,
        $patterns_costi_lavorazioni,
        $patterns_costi_servizi,
        $patterns_costi_personale,
        $patterns_oneri_sociali,          // ← NUOVO
        $patterns_oneri_vari,             // ← NUOVO
        $patterns_ammortamenti,
        $patterns_svalutazioni,
        $patterns_minusvalenze,
        $patterns_altre_minusvalenze,     // ← NUOVO
        $patterns_sopravvenienze_passive, // ← NUOVO
        $patterns_imposte,
        $patterns_oneri_finanziari,
        $patterns_it_software,           // ← NUOVO
        $patterns_marketing,              // ← NUOVO
        $patterns_affitti_utenze         // ← NUOVO
    );
    
    // Genera dinamicamente la WHERE clause basata sui pattern configurati
    $where_costi = buildWhereClauseConti($tutti_patterns_costi);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_costi THEN dare - avere ELSE 0 END), 0) AS totale_costi
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $totale_costi = $result['totale_costi'] ?? 0;
        
        return $totale_costi;
    } catch (PDOException $e) {
        // Log dell'errore
        error_log("Errore nel calcolo costi totali da giornale: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola gli oneri finanziari (interessi passivi) utilizzando i dati del libro giornale
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float Totale degli oneri finanziari nel periodo
 */
function calcola_oneri_finanziari_giornale($db, $data_inizio, $data_fine) {
    
    $patterns_oneri_finanziari = getContiByCategoria($db, 'ONERI_FINANZIARI');
    
    $where_oneri = buildWhereClauseConti($patterns_oneri_finanziari);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE 
                WHEN $where_oneri
                THEN ABS(dare - avere) 
                ELSE 0
            END), 0) AS totale_oneri_finanziari
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $totale_oneri_finanziari = $result['totale_oneri_finanziari'];
        
        return round($totale_oneri_finanziari, 2);
        
    } catch (PDOException $e) {
        error_log("Errore nel calcolo oneri finanziari da giornale: " . $e->getMessage());
        return 0;
    }
}



/**
 * Calcola il margine lordo utilizzando i dati del libro giornale
 * Margine lordo = Ricavi totali - Costi diretti
 */
function calcola_margine_lordo_giornale($db, $data_inizio, $data_fine) {
    
    $ricavi_totali = calcola_ricavi_totali_giornale($db, $data_inizio, $data_fine);
    
    // COSTO DEL VENDUTO = Rimanenze Iniziali + Acquisti - Rimanenze Finali
    $rimanenze_iniziali = get_rimanenze_iniziali($db, $data_inizio);
    $acquisti = calcola_costi_diretti_giornale($db, $data_inizio, $data_fine);
    $rimanenze_finali = get_rimanenze_finali($db, $data_fine);
    
    $costo_venduto = $rimanenze_iniziali + $acquisti - $rimanenze_finali;
    
    // MARGINE LORDO = Ricavi - Costo del Venduto
    $margine_lordo = $ricavi_totali - $costo_venduto;
    
    return $margine_lordo;
}


/**
 * Calcola il margine lordo percentuale utilizzando i dati del libro giornale
 * Margine lordo percentuale = (Margine lordo / Ricavi totali) * 100
 */
function calcola_margine_lordo_percentuale_giornale($db, $data_inizio, $data_fine) {
    
    $ricavi_totali = calcola_ricavi_totali_giornale($db, $data_inizio, $data_fine);
    $margine_lordo = calcola_margine_lordo_giornale($db, $data_inizio, $data_fine);
    
    $margine_lordo_percentuale = ($ricavi_totali > 0) ? ($margine_lordo / $ricavi_totali) * 100 : 0;
    
    return $margine_lordo_percentuale;
}

/**
 * Calcola il Break-even Point (Punto di Pareggio) dal libro giornale
 */
function calcola_break_even_point_giornale($db, $data_inizio, $data_fine) {
    // Validazione parametri
    if (strtotime($data_inizio) > strtotime($data_fine)) {
        error_log("Date non valide: $data_inizio > $data_fine");
        return 0;
    }
    
    try {
        $ricavi = calcola_ricavi_totali_giornale($db, $data_inizio, $data_fine);
        
        // COSTI VARIABILI = COSTO DEL VENDUTO (con rimanenze)
        $rimanenze_iniziali = get_rimanenze_iniziali($db, $data_inizio);
        $acquisti = calcola_costi_diretti_giornale($db, $data_inizio, $data_fine);
        $rimanenze_finali = get_rimanenze_finali($db, $data_fine);
        $costi_variabili = $rimanenze_iniziali + $acquisti - $rimanenze_finali;
        
        // COSTI FISSI = COSTI INDIRETTI
        $costi_fissi = calcola_costi_indiretti_giornale($db, $data_inizio, $data_fine);

        // Controlli di sicurezza
        if ($ricavi <= 0) {
            error_log("Break-even: Ricavi zero o negativi");
            return 0;
        }
        
         if ($costi_variabili >= $ricavi) {
             error_log("Break-even: Costi variabili >= ricavi");
              return 0;
         }

        $margine_contribuzione = 1 - ($costi_variabili / $ricavi);
        
        if ($margine_contribuzione <= 0) {
           error_log("Break-even: Margine contribuzione <= 0");
          return 0;
       }

        $break_even = $costi_fissi / $margine_contribuzione;
        return round($break_even, 2);
        
    } catch (Exception $e) {
        error_log("Errore calcolo Break-even: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola gli ammortamenti utilizzando i dati del libro giornale
 * 
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float Totale degli ammortamenti nel periodo
 */
function calcola_ammortamenti_giornale($db, $data_inizio, $data_fine) {
    
    $patterns_ammortamenti = getContiByCategoria($db, 'AMMORTAMENTI');

    $where_ammortamenti = buildWhereClauseConti($patterns_ammortamenti);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_ammortamenti THEN dare - avere ELSE 0 END), 0) AS totale_ammortamenti
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $totale_ammortamenti = $result['totale_ammortamenti'];
        
        return abs($totale_ammortamenti);
        
    } catch (PDOException $e) {
        error_log("Errore nel calcolo ammortamenti da giornale: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola le imposte e tasse utilizzando i dati del libro giornale
 * 
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float Totale delle imposte nel periodo
 */
function calcola_imposte_giornale($db, $data_inizio, $data_fine) {
    
    $patterns_imposte = getContiByCategoria($db, 'IMPOSTE_TASSE');
    
    $where_imposte = buildWhereClauseConti($patterns_imposte);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_imposte THEN dare - avere ELSE 0 END), 0) AS totale_imposte
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $totale_imposte = $result['totale_imposte'];
        
        return abs($totale_imposte);
        
    } catch (PDOException $e) {
        error_log("Errore nel calcolo imposte da giornale: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola l'EBITDA utilizzando i dati del libro giornale - VERSIONE CORRETTA
 * EBITDA = Ricavi Totali - Costi Operativi (SENZA ammortamenti, imposte, etc.)
 * 
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float EBITDA del periodo
 */
function calcola_ebitda_giornale($db, $data_inizio, $data_fine) {
    
    $ricavi_totali = calcola_ricavi_totali_giornale($db, $data_inizio, $data_fine);
    $costi_operativi = calcola_costi_operativi_giornale($db, $data_inizio, $data_fine);
    
    // EBITDA = Ricavi - Costi Operativi
    $ebitda = $ricavi_totali - $costi_operativi;
    
    return $ebitda;
}

/**
 * Calcola il margine netto utilizzando i dati del libro giornale
 */
function calcola_margine_netto_giornale($db, $data_inizio, $data_fine) {
    $ricavi_totali = calcola_ricavi_totali_giornale($db, $data_inizio, $data_fine);
    $costi_totali = calcola_costi_totali_giornale($db, $data_inizio, $data_fine); 
    
    $margine_netto = $ricavi_totali - $costi_totali;
    
    return $margine_netto;
}

/**
 * Calcola il ROI (Return on Investment) utilizzando i dati del libro giornale
 * Usa la NUOVA LOGICA di mappatura
 */
function calcola_roi_giornale($db, $data_inizio, $data_fine, $aliquota_fiscale = 0.24) {
    try {
        // Calcoli base con validazione
        $ricavi = calcola_ricavi_totali_giornale($db, $data_inizio, $data_fine);
        $costi = calcola_costi_totali_giornale($db, $data_inizio, $data_fine);
        
        if ($ricavi <= 0) {
            error_log("ROI: Ricavi zero o negativi");
            return 0;
        }
        
        $oneri_finanziari = calcola_oneri_finanziari_giornale($db, $data_inizio, $data_fine);
        
        $margine_operativo = $ricavi - $costi;
        $tasse_stimate = max(0, ($margine_operativo - $oneri_finanziari) * $aliquota_fiscale);
        $utile_netto = $margine_operativo - $oneri_finanziari - $tasse_stimate;
        
        // Calcolo capitale investito con TUTTI i crediti
        $patterns_immobilizzazioni = getContiByCategoria($db, 'IMMOBILIZZAZIONI');
        $patterns_crediti_clienti = getContiByCategoria($db, 'CREDITI_CLIENTI');
        $patterns_crediti_soci = getContiByCategoria($db, 'CREDITI_VERSO_SOCI');
        
        // Nuove categorie crediti
        $patterns_crediti_tributari = getContiByCategoria($db, 'CREDITI_TRIBUTARI');
        $patterns_altri_crediti = getContiByCategoria($db, 'ALTRI_CREDITI');
        
        // Rimanenze e liquidità
        $patterns_rimanenze = getContiByCategoria($db, 'RIMANENZE_FINALI');
        $patterns_liquidita_banche = getContiByCategoria($db, 'LIQUIDITA_BANCHE');
        $patterns_liquidita_cassa = getContiByCategoria($db, 'LIQUIDITA_CASSA');
        $patterns_ratei_attivi = getContiByCategoria($db, 'RATEI_ATTIVI');
        $patterns_risconti_attivi = getContiByCategoria($db, 'RISCONTI_ATTIVI');
        
        // Combina TUTTI i pattern di attivo
       $tutti_patterns_attivi = array_merge(
            $patterns_immobilizzazioni,
            $patterns_crediti_clienti,
            $patterns_crediti_soci,
            $patterns_crediti_tributari,
            $patterns_altri_crediti,
            $patterns_rimanenze,
            $patterns_liquidita_banche,
            $patterns_liquidita_cassa,
            $patterns_ratei_attivi,
            $patterns_risconti_attivi
        );
        
        if (empty($tutti_patterns_attivi)) {
            error_log("ROI: Nessun pattern configurato per capitale investito");
            return 0;
        }
        
        $where_attivi = buildWhereClauseConti($tutti_patterns_attivi);
        
        $query = "SELECT 
            COALESCE(SUM(CASE WHEN $where_attivi THEN dare - avere ELSE 0 END), 0) as capitale_investito
            FROM libro_giornale
            WHERE data_registrazione <= ?
            " . buildExcludeSaldiClause($db, 'chiusura') . "
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$data_fine]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $capitale_investito = abs($result['capitale_investito'] ?? 0);
        
        if ($capitale_investito <= 0) {
            error_log("ROI: Capitale investito zero o negativo: $capitale_investito");
            return 0;
        }
        
        $roi = ($utile_netto / $capitale_investito) * 100;
        return round($roi, 2);
        
    } catch (PDOException $e) {
        error_log("Errore calcolo ROI: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola il ROS (Return On Sales) - Margine di Redditività sulle Vendite
 * ROS = (Utile Netto / Ricavi Totali) * 100
 * 
 * Indica quanto profitto genera l'azienda per ogni euro di ricavi
 * 
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float ROS in percentuale
 */
function calcola_ros_giornale($db, $data_inizio, $data_fine) {
    try {
        // Validazione parametri
        if (strtotime($data_inizio) > strtotime($data_fine)) {
            error_log("ROS: Date non valide - $data_inizio > $data_fine");
            return 0;
        }
        
        // Calcola ricavi totali
        $ricavi_totali = calcola_ricavi_totali_giornale($db, $data_inizio, $data_fine);
        
        if ($ricavi_totali <= 0) {
            return 0;
        }
        
        // Calcola utile netto (ricavi - costi)
        $costi_totali = calcola_costi_totali_giornale($db, $data_inizio, $data_fine);
        $utile_netto = $ricavi_totali - $costi_totali;
        
        // Calcola ROS in percentuale
        $ros = ($utile_netto / $ricavi_totali) * 100;
        
        return round($ros, 2);
        
    } catch (Exception $e) {
        error_log("Errore calcolo ROS: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola il ROE (Return on Equity) utilizzando i dati del libro giornale
 */

function calcola_roe_giornale($db, $data_inizio, $data_fine, $aliquota_fiscale = 0.24) {
    try {
        // Calcoli base identici al ROI
        $ricavi = calcola_ricavi_totali_giornale($db, $data_inizio, $data_fine);
        $costi = calcola_costi_totali_giornale($db, $data_inizio, $data_fine);
        
        if ($ricavi <= 0) {
            error_log("ROE: Ricavi zero o negativi");
            return 0;
        }
        
        $oneri_finanziari = calcola_oneri_finanziari_giornale($db, $data_inizio, $data_fine);
        
        $margine_operativo = $ricavi - $costi;
        $tasse_stimate = max(0, ($margine_operativo - $oneri_finanziari) * $aliquota_fiscale);
        $utile_netto = $margine_operativo - $oneri_finanziari - $tasse_stimate;
        
        // CALCOLO PATRIMONIO NETTO = CAPITALE FISSO + RISERVE DINAMICHE
        
        // 1. CAPITALE SOCIALE con logica intelligente (evita doppi conteggi)
        $capitale_sociale = get_capitale_sociale_effettivo($db, $data_fine);
        
        // 2. RISERVE E UTILI DINAMICI dal giornale (SENZA capitale sociale)
        $patterns_patrimonio = getContiByCategoria($db, 'PATRIMONIO_NETTO');
        
        if (!empty($patterns_patrimonio)) {
            $where_patrimonio = buildWhereClauseConti($patterns_patrimonio);
            
            $query_riserve = "SELECT 
                COALESCE(SUM(CASE WHEN $where_patrimonio THEN avere - dare ELSE 0 END), 0) as riserve_utili
                FROM libro_giornale
                WHERE data_registrazione <= ?
                " . buildExcludeSaldiClause($db, 'chiusura') . "
            ";
                
            $stmt = $db->prepare($query_riserve);
            $stmt->execute([$data_fine]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $riserve_utili = $result['riserve_utili'] ?? 0;
        } else {
            error_log("ROE: Nessun pattern configurato per PATRIMONIO_NETTO");
            $riserve_utili = 0;
        }
        
        // PATRIMONIO TOTALE
        $patrimonio_netto = $capitale_sociale + $riserve_utili;
        
        if ($patrimonio_netto <= 0) {
            error_log("ROE: Patrimonio netto zero o negativo: $patrimonio_netto");
            return 0;
        }
        
        $roe = ($utile_netto / $patrimonio_netto) * 100;
        return round($roe, 2);
        
    } catch (PDOException $e) {
        error_log("Errore calcolo ROE: " . $e->getMessage());
        return 0;
    }
}

/**
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float Current Ratio
 */
function calcola_current_ratio_giornale($db, $data_inizio, $data_fine) {
    try {
        // ATTIVO CORRENTE COMPLETO
        // Liquidità
        $conti_liquidita_banche = getContiByCategoria($db, 'LIQUIDITA_BANCHE');
        $conti_liquidita_cassa = getContiByCategoria($db, 'LIQUIDITA_CASSA');
        $conti_ratei_attivi = getContiByCategoria($db, 'RATEI_ATTIVI');
        $conti_risconti_attivi = getContiByCategoria($db, 'RISCONTI_ATTIVI');
        
        // Crediti commerciali e altri
        $conti_crediti_clienti = getContiByCategoria($db, 'CREDITI_CLIENTI');
        $conti_crediti_tributari = getContiByCategoria($db, 'CREDITI_TRIBUTARI');
        $conti_altri_crediti = getContiByCategoria($db, 'ALTRI_CREDITI');
        
        // Combina TUTTI i conti di attivo corrente
       $tutti_conti_attivo = array_merge(
            $conti_liquidita_banche, 
            $conti_liquidita_cassa, 
            $conti_crediti_clienti,
            $conti_crediti_tributari,
            $conti_altri_crediti,
            $conti_ratei_attivi,
            $conti_risconti_attivi
        );
        
        if (!empty($tutti_conti_attivo)) {
            $where_attivo_corrente = buildWhereClauseConti($tutti_conti_attivo);
            
            $query_attivo = "SELECT 
                COALESCE(SUM(dare - avere), 0) as attivo_corrente
                FROM libro_giornale
                WHERE $where_attivo_corrente
                AND data_registrazione <= :data_fine
                " . buildExcludeSaldiClause($db, 'chiusura') . "
            ";
            
            $stmt = $db->prepare($query_attivo);
            $stmt->bindParam(':data_fine', $data_fine);
            $stmt->execute();
            $attivo_corrente = (float) $stmt->fetchColumn();
        } else {
            $attivo_corrente = 0;
        }

        // PASSIVO CORRENTE COMPLETO
        $conti_debiti_fornitori = getContiByCategoria($db, 'DEBITI_FORNITORI');
        $conti_altri_debiti = getContiByCategoria($db, 'ALTRI_DEBITI');  // ← NUOVO
        $conti_ratei_passivi = getContiByCategoria($db, 'RATEI_PASSIVI');
        $conti_risconti_passivi = getContiByCategoria($db, 'RISCONTI_PASSIVI');

        
        // Combina TUTTI i conti di passivo corrente
         $tutti_conti_passivo = array_merge(
            $conti_debiti_fornitori,
            $conti_altri_debiti,
            $conti_ratei_passivi,
            $conti_risconti_passivi
        );
        
        if (!empty($tutti_conti_passivo)) {
            $where_passivo_corrente = buildWhereClauseConti($tutti_conti_passivo);
            
            $query_passivo = "SELECT 
                COALESCE(SUM(avere - dare), 0) as passivo_corrente
                FROM libro_giornale
                WHERE $where_passivo_corrente
                AND data_registrazione <= :data_fine
                " . buildExcludeSaldiClause($db, 'chiusura') . "
            ";
            
            $stmt = $db->prepare($query_passivo);
            $stmt->bindParam(':data_fine', $data_fine);
            $stmt->execute();
            $passivo_corrente = (float) $stmt->fetchColumn();
        } else {
            $passivo_corrente = 0;
        }

        if (abs($passivo_corrente) <= 0.01) {
            return 0;
        }
        $current_ratio = $attivo_corrente / abs($passivo_corrente);

        return round($current_ratio, 2);
    } catch (PDOException $e) {
        error_log("Errore nel calcolo current ratio: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola l'indice di indebitamento (Debt Ratio) usando i dati del libro giornale
 * Formula: Totale Debiti / Patrimonio Netto
 *
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float Indice di indebitamento
 */
function calcola_indice_indebitamento_giornale($db, $data_inizio, $data_fine) {
    
    try {
        // DEBITI TOTALI - usa la nuova logica per ottenere tutti i tipi di debito
        $patterns_debiti_fornitori = getContiByCategoria($db, 'DEBITI_FORNITORI');
        $patterns_debiti_banche = getContiByCategoria($db, 'DEBITI_BANCHE');
        $patterns_debiti_verso_soci = getContiByCategoria($db, 'DEBITI_VERSO_SOCI');
        $patterns_oneri_finanziari = getContiByCategoria($db, 'ONERI_FINANZIARI');
        $patterns_ratei_passivi = getContiByCategoria($db, 'RATEI_PASSIVI');
        $patterns_risconti_passivi = getContiByCategoria($db, 'RISCONTI_PASSIVI');

        
        // Combina tutti i pattern di debito
        $tutti_patterns_debiti = array_merge(
            $patterns_debiti_fornitori, 
            $patterns_debiti_banche,
            $patterns_debiti_verso_soci,
            $patterns_oneri_finanziari,
            $patterns_ratei_passivi,
            $patterns_risconti_passivi
        );
        
        if (!empty($tutti_patterns_debiti)) {
            $where_debiti = buildWhereClauseConti($tutti_patterns_debiti);
            
            $query_debiti = "SELECT 
                COALESCE(SUM(CASE WHEN $where_debiti THEN avere - dare ELSE 0 END), 0) as totale_debiti
                FROM libro_giornale
                WHERE data_registrazione <= ?
                " . buildExcludeSaldiClause($db, 'chiusura') . "
            ";
                
                
            $stmt = $db->prepare($query_debiti);
            $stmt->execute([$data_fine]);
            $totale_debiti = (float) $stmt->fetchColumn();
        } else {
            $totale_debiti = 0;
        }
        
        // PATRIMONIO NETTO - usa la nuova logica
        $capitale_sociale = get_capitale_sociale_effettivo($db, $data_fine);
        $patterns_patrimonio = getContiByCategoria($db, 'PATRIMONIO_NETTO');
        
        if (!empty($patterns_patrimonio)) {
            $where_patrimonio = buildWhereClauseConti($patterns_patrimonio);
            
            $query_patrimonio = "SELECT 
                COALESCE(SUM(CASE WHEN $where_patrimonio THEN avere - dare ELSE 0 END), 0) as riserve_utili
                FROM libro_giornale
                WHERE data_registrazione <= ?
                " . buildExcludeSaldiClause($db, 'chiusura') . "
                ";
                
                
            $stmt = $db->prepare($query_patrimonio);
            $stmt->execute([$data_fine]);
            $riserve_utili = (float) $stmt->fetchColumn();
        } else {
            $riserve_utili = 0;
        }
        
        $patrimonio_netto = $capitale_sociale + $riserve_utili;
        
        if ($patrimonio_netto == 0) {
            return 0;
        }
        
        $indice_indebitamento = $totale_debiti / $patrimonio_netto;
        return round($indice_indebitamento, 2);
        
    } catch (PDOException $e) {
        error_log("Errore nel calcolo indice di indebitamento: " . $e->getMessage());
        return 0;
    }
}


/**
 * Calcola il Quick Ratio basandosi sulla tabella libro_giornale
 * Quick Ratio = (Attività correnti - Rimanenze) / Passività correnti
 * VERSIONE CORRETTA: usa le categorie realmente mappate e include TUTTI i crediti
 *
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float Quick Ratio
 */
function calcola_quick_ratio_giornale($db, $data_inizio, $data_fine) {
    try {
        // ATTIVO LIQUIDO = Liquidità + Crediti (escluse rimanenze)
        // Liquidità
        $conti_liquidita_banche = getContiByCategoria($db, 'LIQUIDITA_BANCHE');
        $conti_liquidita_cassa = getContiByCategoria($db, 'LIQUIDITA_CASSA');
        
        // Crediti (più liquidi delle rimanenze, quindi inclusi)
        $conti_crediti_clienti = getContiByCategoria($db, 'CREDITI_CLIENTI');
        $conti_crediti_tributari = getContiByCategoria($db, 'CREDITI_TRIBUTARI');
        $conti_altri_crediti = getContiByCategoria($db, 'ALTRI_CREDITI');
        
        // Combina liquidità immediata + crediti
        $tutti_conti_liquidi = array_merge(
            $conti_liquidita_banche, 
            $conti_liquidita_cassa,
            $conti_crediti_clienti,
            $conti_crediti_tributari,
            $conti_altri_crediti
        );
        
        if (!empty($tutti_conti_liquidi)) {
            $where_attivo_liquido = buildWhereClauseConti($tutti_conti_liquidi);
            
            $query_attivo = "SELECT 
                COALESCE(SUM(dare - avere), 0) as attivo_liquido
                FROM libro_giornale
                WHERE $where_attivo_liquido
                AND data_registrazione <= :data_fine
                " . buildExcludeSaldiClause($db, 'chiusura') . "
            ";
                
                
            $stmt = $db->prepare($query_attivo);
            $stmt->bindParam(':data_fine', $data_fine);
            $stmt->execute();
            $attivo_liquido = (float) $stmt->fetchColumn();
        } else {
            $attivo_liquido = 0;
            error_log("DEBUG Quick Ratio - Nessun conto liquidità trovato!");
        }
        
        // PASSIVO CORRENTE = Debiti Fornitori + Altri Debiti
        $conti_debiti_fornitori = getContiByCategoria($db, 'DEBITI_FORNITORI');
        $conti_altri_debiti = getContiByCategoria($db, 'ALTRI_DEBITI');
        $conti_ratei_passivi = getContiByCategoria($db, 'RATEI_PASSIVI');
        $conti_risconti_passivi = getContiByCategoria($db, 'RISCONTI_PASSIVI');
        
        $tutti_conti_passivo = array_merge(
            $conti_debiti_fornitori,
            $conti_altri_debiti,
            $conti_ratei_passivi,
            $conti_risconti_passivi
        );
        
        if (!empty($tutti_conti_passivo)) {
            $where_passivo_corrente = buildWhereClauseConti($tutti_conti_passivo);
            
            $query_passivo = "SELECT 
                COALESCE(SUM(avere - dare), 0) as passivo_corrente
                FROM libro_giornale
                WHERE $where_passivo_corrente
                AND data_registrazione <= :data_fine
                " . buildExcludeSaldiClause($db, 'chiusura') . "
            ";
                
                
            $stmt = $db->prepare($query_passivo);
            $stmt->bindParam(':data_fine', $data_fine);
            $stmt->execute();
            $passivo_corrente = (float) $stmt->fetchColumn();
        } else {
            $passivo_corrente = 0;
        }
        
        if (abs($passivo_corrente) <= 0.01) {
            return 0;
        }

        $quick_ratio = $attivo_liquido / abs($passivo_corrente);
        
        return round($quick_ratio, 2);
    } catch (PDOException $e) {
        error_log("Errore nel calcolo quick ratio: " . $e->getMessage());
        return 0;
    }
}


/**
 * Calcola il Patrimonio Netto usando i dati del libro giornale
 * Formula: Capitale Sociale + Riserve e Utili
 *
 * @param PDO $db Connessione al database
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float Patrimonio Netto
 */
function calcola_patrimonio_netto_giornale($db, $data_fine) {
    try {
        // CAPITALE SOCIALE
        $capitale_sociale = get_capitale_sociale_effettivo($db, $data_fine);
        
        // RISERVE E UTILI
        $patterns_patrimonio = getContiByCategoria($db, 'PATRIMONIO_NETTO');
        
        if (!empty($patterns_patrimonio)) {
            $where_patrimonio = buildWhereClauseConti($patterns_patrimonio);
            
            $query_patrimonio = "SELECT 
                COALESCE(SUM(CASE WHEN $where_patrimonio THEN avere - dare ELSE 0 END), 0) as riserve_utili
                FROM libro_giornale
                WHERE data_registrazione <= ?
                " . buildExcludeSaldiClause($db, 'chiusura') . "
            ";
                
            $stmt = $db->prepare($query_patrimonio);
            $stmt->execute([$data_fine]);
            $riserve_utili = (float) $stmt->fetchColumn();
        } else {
            $riserve_utili = 0;
        }
        
        $patrimonio_netto = $capitale_sociale + $riserve_utili;
        
        return $patrimonio_netto;
        
    } catch (PDOException $e) {
        error_log("Errore nel calcolo patrimonio netto: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola il DSO (Days Sales Outstanding) dal libro giornale
 * Formula: (Crediti vs Clienti / Ricavi annualizzati) * 365
 *
 * @param PDO $conn Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float DSO (giorni medi di incasso)
 */
function calcola_dso_giornale($conn, $data_inizio, $data_fine) {
    
    // 1. Crediti vs Clienti - SALDO FINALE alla data_fine
    $patterns_crediti_clienti = getContiByCategoria($conn, 'CREDITI_CLIENTI');
    
    if (!empty($patterns_crediti_clienti)) {
        $where_crediti = buildWhereClauseConti($patterns_crediti_clienti);
        
        $query_crediti = "
            SELECT COALESCE(SUM(CASE WHEN $where_crediti THEN dare - avere ELSE 0 END), 0)
            FROM libro_giornale
            WHERE data_registrazione <= ?
            " . buildExcludeSaldiClause($conn, 'entrambi') . "
        ";
        $stmt = $conn->prepare($query_crediti);
        $stmt->execute([$data_fine]);
        $crediti_clienti = abs($stmt->fetchColumn());
    } else {
        $crediti_clienti = 0;
    }
    
    // 2. Ricavi del periodo - recupera tutti i tipi di ricavi
    $patterns_ricavi_vendite = getContiByCategoria($conn, 'RICAVI_VENDITE');
    $patterns_ricavi_prestazioni = getContiByCategoria($conn, 'RICAVI_PRESTAZIONI');
    $patterns_ricavi_corrispettivi = getContiByCategoria($conn, 'RICAVI_CORRISPETTIVI');
    
    // Combina tutti i pattern di ricavo
    $tutti_patterns_ricavi = array_merge(
        $patterns_ricavi_vendite,
        $patterns_ricavi_prestazioni,
        $patterns_ricavi_corrispettivi
    );
    
    if (!empty($tutti_patterns_ricavi)) {
        $where_ricavi = buildWhereClauseConti($tutti_patterns_ricavi);
        
        $query_ricavi = "
            SELECT COALESCE(SUM(CASE WHEN $where_ricavi THEN avere - dare ELSE 0 END), 0)
            FROM libro_giornale
            WHERE data_registrazione BETWEEN ? AND ?
            " . buildExcludeSaldiClause($conn, 'entrambi') . "
        ";
        $stmt = $conn->prepare($query_ricavi);
        $stmt->execute([$data_inizio, $data_fine]);
        $ricavi = abs($stmt->fetchColumn());
    } else {
        $ricavi = 0;
    }
    
    // 3. Calcolo DSO
    $giorni_periodo = (strtotime($data_fine) - strtotime($data_inizio)) / 86400 + 1;
    $ricavi_giornalieri = $ricavi / $giorni_periodo;
    $ricavi_annualizzati = $ricavi_giornalieri * 365;
    $dso = $ricavi_annualizzati > 0 ? round(($crediti_clienti / $ricavi_annualizzati) * 365, 2) : 0;
    
    return $dso;
}

/**
 * Calcola il DPO (Days Payable Outstanding) usando il libro giornale
 * DPO = (Debiti Fornitori / Acquisti annualizzati) * 365
 */
function calcola_dpo_giornale($conn, $data_inizio, $data_fine) {
    
    // Usa la nuova logica per ottenere i debiti verso fornitori
    $patterns_debiti_fornitori = getContiByCategoria($conn, 'DEBITI_FORNITORI');
    
    if (!empty($patterns_debiti_fornitori)) {
        $where_debiti = buildWhereClauseConti($patterns_debiti_fornitori);
        
        $query_debiti = "
            SELECT COALESCE(SUM(CASE WHEN $where_debiti THEN avere - dare ELSE 0 END), 0)
            FROM libro_giornale
            WHERE data_registrazione <= ?
            " . buildExcludeSaldiClause($conn, 'entrambi') . "
        ";
        $stmt = $conn->prepare($query_debiti);
        $stmt->execute([$data_fine]);
        $debiti_fornitori = abs($stmt->fetchColumn());
    } else {
        $debiti_fornitori = 0;
    }
    
    // Acquisti operativi del periodo - ottieni tutti i tipi di costi
    $patterns_costi_diretti = getContiByCategoria($conn, 'COSTI_DIRETTI');
    $patterns_costi_indiretti = getContiByCategoria($conn, 'COSTI_INDIRETTI');
    $patterns_costi_materie = getContiByCategoria($conn, 'COSTI_MATERIE_PRIME');
    $patterns_costi_lavorazioni = getContiByCategoria($conn, 'COSTI_LAVORAZIONI');
    $patterns_costi_servizi = getContiByCategoria($conn, 'COSTI_SERVIZI');
    
    // Combina tutti i pattern di costo
    $tutti_patterns_costi = array_merge(
        $patterns_costi_diretti,
        $patterns_costi_indiretti,
        $patterns_costi_materie,
        $patterns_costi_lavorazioni,
        $patterns_costi_servizi
    );
    
    if (!empty($tutti_patterns_costi)) {
        $where_costi = buildWhereClauseConti($tutti_patterns_costi);
        
        $query_acquisti = "
            SELECT COALESCE(SUM(CASE WHEN $where_costi THEN dare - avere ELSE 0 END), 0)
            FROM libro_giornale
            WHERE data_registrazione BETWEEN ? AND ?
            " . buildExcludeSaldiClause($conn, 'entrambi') . "
        ";
        $stmt = $conn->prepare($query_acquisti);
        $stmt->execute([$data_inizio, $data_fine]);
        $acquisti = abs($stmt->fetchColumn());
    } else {
        $acquisti = 0;
    }
    
    // Calcolo DPO
    $giorni_periodo = (strtotime($data_fine) - strtotime($data_inizio)) / 86400 + 1;
    $acquisti_giornalieri = $acquisti / $giorni_periodo;
    $acquisti_annualizzati = $acquisti_giornalieri * 365;
    
    $dpo = $acquisti_annualizzati > 0 ? round(($debiti_fornitori / $acquisti_annualizzati) * 365, 2) : 0;
    
    return $dpo;
}


/**
 * Calcola gli indicatori di efficienza operativa basandosi sulla tabella libro_giornale
 * 
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @param string $periodo_confronto_inizio Data inizio periodo confronto (opzionale)
 * @param string $periodo_confronto_fine Data fine periodo confronto (opzionale)
 * @param int $num_dipendenti Numero di dipendenti dell'azienda (default 1)
 * @return array Array con gli indicatori di efficienza operativa
 */
function calcola_efficienza_operativa_giornale($db, $data_inizio, $data_fine, $periodo_confronto_inizio = null, $periodo_confronto_fine = null, $num_dipendenti = 1) {
    
    // Se non sono specificate date per il periodo di confronto, calcola periodo precedente di uguale durata
    if ($periodo_confronto_inizio === null || $periodo_confronto_fine === null) {
        $durata_periodo = strtotime($data_fine) - strtotime($data_inizio);
        $periodo_confronto_fine = date('Y-m-d', strtotime($data_inizio) - 86400); // Giorno prima dell'inizio
        $periodo_confronto_inizio = date('Y-m-d', strtotime($periodo_confronto_fine) - $durata_periodo);
    }
    
    try {
        // Utilizziamo le funzioni esistenti per i calcoli di base (già aggiornate!)
        $ricavi_totali = calcola_ricavi_totali_giornale($db, $data_inizio, $data_fine);
        $costi_totali = calcola_costi_totali_giornale($db, $data_inizio, $data_fine);
        $costi_diretti = calcola_costi_diretti_giornale($db, $data_inizio, $data_fine);
        $costi_indiretti = calcola_costi_indiretti_giornale($db, $data_inizio, $data_fine);
        
        // Usa la nuova logica per contare le transazioni
        $patterns_ricavi_vendite = getContiByCategoria($db, 'RICAVI_VENDITE');
        $patterns_ricavi_prestazioni = getContiByCategoria($db, 'RICAVI_PRESTAZIONI');
        $patterns_ricavi_corrispettivi = getContiByCategoria($db, 'RICAVI_CORRISPETTIVI');
        $tutti_patterns_ricavi = array_merge($patterns_ricavi_vendite, $patterns_ricavi_prestazioni, $patterns_ricavi_corrispettivi);
        
        $patterns_costi_diretti = getContiByCategoria($db, 'COSTI_DIRETTI');
        $patterns_costi_indiretti = getContiByCategoria($db, 'COSTI_INDIRETTI');
        $patterns_costi_materie = getContiByCategoria($db, 'COSTI_MATERIE_PRIME');
        $patterns_costi_lavorazioni = getContiByCategoria($db, 'COSTI_LAVORAZIONI');
        $patterns_costi_servizi = getContiByCategoria($db, 'COSTI_SERVIZI');
        $tutti_patterns_costi = array_merge($patterns_costi_diretti, $patterns_costi_indiretti, $patterns_costi_materie, $patterns_costi_lavorazioni, $patterns_costi_servizi);
        
        $num_transazioni_vendita = 0;
        $num_transazioni_acquisto = 0;
        
        if (!empty($tutti_patterns_ricavi)) {
            $where_ricavi = buildWhereClauseConti($tutti_patterns_ricavi);
            $query_transazioni_vendita = "SELECT 
                COUNT(DISTINCT num_registrazione) as num_transazioni_vendita
                FROM libro_giornale
                WHERE $where_ricavi
                AND data_registrazione BETWEEN ? AND ?
                " . buildExcludeSaldiClause($db, 'entrambi') . "
            ";
            $stmt_transazioni = $db->prepare($query_transazioni_vendita);
            $stmt_transazioni->execute([$data_inizio, $data_fine]);
            $result = $stmt_transazioni->fetch(PDO::FETCH_ASSOC);
            $num_transazioni_vendita = $result['num_transazioni_vendita'] ?? 0;
        }
        
        if (!empty($tutti_patterns_costi)) {
            $where_costi = buildWhereClauseConti($tutti_patterns_costi);
            $query_transazioni_acquisto = "SELECT 
                COUNT(DISTINCT num_registrazione) as num_transazioni_acquisto
                FROM libro_giornale
                WHERE $where_costi
                AND data_registrazione BETWEEN ? AND ?
                " . buildExcludeSaldiClause($db, 'entrambi') . "
            ";
            
            $stmt_transazioni = $db->prepare($query_transazioni_acquisto);
            $stmt_transazioni->execute([$data_inizio, $data_fine]);
            $result = $stmt_transazioni->fetch(PDO::FETCH_ASSOC);
            $num_transazioni_acquisto = $result['num_transazioni_acquisto'] ?? 0;
        }
        
        // Usa la nuova logica per costi del personale
        $patterns_personale = getContiByCategoria($db, 'COSTI_PERSONALE');
        
        $costi_personale = 0;
        if (!empty($patterns_personale)) {
            $where_personale = buildWhereClauseConti($patterns_personale);
            
            $query_personale = "SELECT 
                COALESCE(SUM(CASE WHEN $where_personale THEN dare - avere ELSE 0 END), 0) as costi_personale
                FROM libro_giornale
                WHERE data_registrazione BETWEEN ? AND ?
                " . buildExcludeSaldiClause($db, 'entrambi') . "
            ";
            
            $stmt_personale = $db->prepare($query_personale);
            $stmt_personale->execute([$data_inizio, $data_fine]);
            $result_personale = $stmt_personale->fetch(PDO::FETCH_ASSOC);
            $costi_personale = abs($result_personale['costi_personale'] ?? 0);
        }
        
        // Calcola metriche di efficienza per il periodo corrente
        $durata_periodo_giorni = (strtotime($data_fine) - strtotime($data_inizio)) / (60 * 60 * 24);
        $durata_periodo_mesi = $durata_periodo_giorni / 30.44; // Media giorni per mese
        
        $metriche_correnti = [
            'ricavi_totali' => $ricavi_totali,
            'costi_totali' => $costi_totali,
            'costi_diretti' => $costi_diretti,
            'costi_indiretti' => $costi_indiretti,
            'costi_personale' => $costi_personale,
            'margine_operativo' => $ricavi_totali - $costi_totali,
            'num_transazioni_vendita' => $num_transazioni_vendita,
            'num_transazioni_acquisto' => $num_transazioni_acquisto,
            'ricavi_per_dipendente' => $num_dipendenti > 0 ? $ricavi_totali / $num_dipendenti : $ricavi_totali,
            'ricavi_per_giorno' => $durata_periodo_giorni > 0 ? $ricavi_totali / $durata_periodo_giorni : 0,
            'ricavi_per_mese' => $durata_periodo_mesi > 0 ? $ricavi_totali / $durata_periodo_mesi : 0,
            'costi_per_transazione' => $num_transazioni_vendita > 0 ? $costi_totali / $num_transazioni_vendita : 0,
            'valore_medio_transazione' => $num_transazioni_vendita > 0 ? $ricavi_totali / $num_transazioni_vendita : 0,
            'rapporto_costi_ricavi' => $ricavi_totali > 0 ? ($costi_totali / $ricavi_totali) * 100 : 0,
            'percentuale_costi_personale' => $costi_totali > 0 ? ($costi_personale / $costi_totali) * 100 : 0,
            'durata_periodo_giorni' => $durata_periodo_giorni,
            'durata_periodo_mesi' => $durata_periodo_mesi
        ];
        
        // Dati per periodo di confronto
        if ($periodo_confronto_inizio && $periodo_confronto_fine) {
            // Utilizziamo le funzioni esistenti per i calcoli di base del periodo di confronto (già aggiornate!)
            $ricavi_totali_confronto = calcola_ricavi_totali_giornale($db, $periodo_confronto_inizio, $periodo_confronto_fine);
            $costi_totali_confronto = calcola_costi_totali_giornale($db, $periodo_confronto_inizio, $periodo_confronto_fine);
            $costi_diretti_confronto = calcola_costi_diretti_giornale($db, $periodo_confronto_inizio, $periodo_confronto_fine);
            $costi_indiretti_confronto = calcola_costi_indiretti_giornale($db, $periodo_confronto_inizio, $periodo_confronto_fine);
            
            // Query per contare le transazioni nel periodo di confronto
            $num_transazioni_vendita_confronto = 0;
            $num_transazioni_acquisto_confronto = 0;
            
            if (!empty($tutti_patterns_ricavi)) {
                $stmt_transazioni_confronto = $db->prepare($query_transazioni_vendita);
                $stmt_transazioni_confronto->execute([$periodo_confronto_inizio, $periodo_confronto_fine]);
                $result = $stmt_transazioni_confronto->fetch(PDO::FETCH_ASSOC);
                $num_transazioni_vendita_confronto = $result['num_transazioni_vendita'] ?? 0;
            }
            
            if (!empty($tutti_patterns_costi)) {
                $stmt_transazioni_confronto = $db->prepare($query_transazioni_acquisto);
                $stmt_transazioni_confronto->execute([$periodo_confronto_inizio, $periodo_confronto_fine]);
                $result = $stmt_transazioni_confronto->fetch(PDO::FETCH_ASSOC);
                $num_transazioni_acquisto_confronto = $result['num_transazioni_acquisto'] ?? 0;
            }
            
            // Query per costi del personale nel periodo di confronto
            $costi_personale_confronto = 0;
            if (!empty($patterns_personale)) {
                $stmt_personale_confronto = $db->prepare($query_personale);
                $stmt_personale_confronto->execute([$periodo_confronto_inizio, $periodo_confronto_fine]);
                $result_personale_confronto = $stmt_personale_confronto->fetch(PDO::FETCH_ASSOC);
                $costi_personale_confronto = abs($result_personale_confronto['costi_personale'] ?? 0);
            }
            
            // Calcola metriche di efficienza per il periodo di confronto
            $durata_periodo_confronto_giorni = (strtotime($periodo_confronto_fine) - strtotime($periodo_confronto_inizio)) / (60 * 60 * 24);
            $durata_periodo_confronto_mesi = $durata_periodo_confronto_giorni / 30.44;
            
            $metriche_confronto = [
                'ricavi_totali' => $ricavi_totali_confronto,
                'costi_totali' => $costi_totali_confronto,
                'costi_diretti' => $costi_diretti_confronto,
                'costi_indiretti' => $costi_indiretti_confronto,
                'costi_personale' => $costi_personale_confronto,
                'margine_operativo' => $ricavi_totali_confronto - $costi_totali_confronto,
                'num_transazioni_vendita' => $num_transazioni_vendita_confronto,
                'num_transazioni_acquisto' => $num_transazioni_acquisto_confronto,
                'ricavi_per_dipendente' => $num_dipendenti > 0 ? $ricavi_totali_confronto / $num_dipendenti : $ricavi_totali_confronto,
                'ricavi_per_giorno' => $durata_periodo_confronto_giorni > 0 ? $ricavi_totali_confronto / $durata_periodo_confronto_giorni : 0,
                'ricavi_per_mese' => $durata_periodo_confronto_mesi > 0 ? $ricavi_totali_confronto / $durata_periodo_confronto_mesi : 0,
                'costi_per_transazione' => $num_transazioni_vendita_confronto > 0 ? $costi_totali_confronto / $num_transazioni_vendita_confronto : 0,
                'valore_medio_transazione' => $num_transazioni_vendita_confronto > 0 ? $ricavi_totali_confronto / $num_transazioni_vendita_confronto : 0,
                'rapporto_costi_ricavi' => $ricavi_totali_confronto > 0 ? ($costi_totali_confronto / $ricavi_totali_confronto) * 100 : 0,
                'percentuale_costi_personale' => $costi_totali_confronto > 0 ? ($costi_personale_confronto / $costi_totali_confronto) * 100 : 0,
                'durata_periodo_giorni' => $durata_periodo_confronto_giorni,
                'durata_periodo_mesi' => $durata_periodo_confronto_mesi
            ];
            
            // Calcola variazioni tra i due periodi
            $variazioni = [];
            
            foreach ($metriche_correnti as $key => $valore) {
                if (isset($metriche_confronto[$key]) && $metriche_confronto[$key] > 0) {
                    $variazioni[$key] = (($valore - $metriche_confronto[$key]) / $metriche_confronto[$key]) * 100;
                } else {
                    $variazioni[$key] = $valore > 0 ? 100 : 0;
                }
            }
        } else {
            $metriche_confronto = null;
            $variazioni = null;
        }
        
        // Valutazione dell'efficienza (resto del codice rimane invariato)
        $valutazioni = [];
        
        // Rapporto costi/ricavi (più basso è meglio)
        if ($metriche_correnti['rapporto_costi_ricavi'] < 70) {
            $valutazioni['rapporto_costi_ricavi'] = 'ottimo';
        } elseif ($metriche_correnti['rapporto_costi_ricavi'] < 80) {
            $valutazioni['rapporto_costi_ricavi'] = 'buono';
        } elseif ($metriche_correnti['rapporto_costi_ricavi'] < 90) {
            $valutazioni['rapporto_costi_ricavi'] = 'sufficiente';
        } else {
            $valutazioni['rapporto_costi_ricavi'] = 'critico';
        }
        
        // Ricavi per dipendente (più alto è meglio)
        if ($metriche_correnti['ricavi_per_dipendente'] > 200000) {
            $valutazioni['ricavi_per_dipendente'] = 'ottimo';
        } elseif ($metriche_correnti['ricavi_per_dipendente'] > 150000) {
            $valutazioni['ricavi_per_dipendente'] = 'buono';
        } elseif ($metriche_correnti['ricavi_per_dipendente'] > 100000) {
            $valutazioni['ricavi_per_dipendente'] = 'sufficiente';
        } else {
            $valutazioni['ricavi_per_dipendente'] = 'da migliorare';
        }
        
        // Margine operativo in percentuale
        $margine_percentuale = $metriche_correnti['ricavi_totali'] > 0 ? 
            ($metriche_correnti['margine_operativo'] / $metriche_correnti['ricavi_totali']) * 100 : 0;
        
        if ($margine_percentuale > 20) {
            $valutazioni['margine_operativo'] = 'ottimo';
        } elseif ($margine_percentuale > 15) {
            $valutazioni['margine_operativo'] = 'buono';
        } elseif ($margine_percentuale > 10) {
            $valutazioni['margine_operativo'] = 'sufficiente';
        } else {
            $valutazioni['margine_operativo'] = 'critico';
        }
        
        // Restituisci risultati
        return [
            'periodo_corrente' => [
                'inizio' => $data_inizio,
                'fine' => $data_fine
            ],
            'periodo_confronto' => [
                'inizio' => $periodo_confronto_inizio,
                'fine' => $periodo_confronto_fine
            ],
            'metriche_correnti' => $metriche_correnti,
            'metriche_confronto' => $metriche_confronto,
            'variazioni' => $variazioni,
            'valutazioni' => $valutazioni,
            'margine_percentuale' => $margine_percentuale,
            'num_dipendenti' => $num_dipendenti
        ];
    } catch (PDOException $e) {
        error_log("ERRORE Calcolo Efficienza Operativa: " . $e->getMessage());
        return [
            'errore' => $e->getMessage(),
            'metriche_correnti' => [],
            'metriche_confronto' => [],
            'variazioni' => []
        ];
    }
}

/**
 * Calcola l'Overhead Ratio basandosi sulla tabella libro_giornale
 * Overhead Ratio = (Costi indiretti / Ricavi totali) * 100
 * 
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float Overhead Ratio (percentuale)
 */
function calcola_overhead_ratio_giornale($db, $data_inizio, $data_fine) {
    try {
        $costi_indiretti = calcola_costi_indiretti_giornale($db, $data_inizio, $data_fine);
        $ricavi_totali = calcola_ricavi_totali_giornale($db, $data_inizio, $data_fine);
        
        if ($ricavi_totali <= 0) {
            return 0;
        }
        
        $overhead_ratio = ($costi_indiretti / $ricavi_totali) * 100;
        return round($overhead_ratio, 2);
        
    } catch (Exception $e) {
        error_log("Errore calcolo Overhead Ratio: " . $e->getMessage());
        return 0;
    }
}


function analisi_stagionalita_giornale($db, $anno, $anno_precedente = null) {

    if ($anno_precedente === null) {
        $anno_precedente = $anno - 1;
    }

    try {
        // Recupero mapping NEITCUS (identico comportamento)
        $patterns_ricavi_vendite       = getContiByCategoria($db, 'RICAVI_VENDITE');
        $patterns_ricavi_prestazioni   = getContiByCategoria($db, 'RICAVI_PRESTAZIONI');
        $patterns_ricavi_corrispettivi = getContiByCategoria($db, 'RICAVI_CORRISPETTIVI');
        $patterns_crediti_clienti      = getContiByCategoria($db, 'CREDITI_CLIENTI');
        $tutti_patterns_ricavi         = array_merge($patterns_ricavi_vendite, $patterns_ricavi_prestazioni, $patterns_ricavi_corrispettivi);

        $patterns_costi_diretti        = getContiByCategoria($db, 'COSTI_DIRETTI');
        $patterns_costi_indiretti      = getContiByCategoria($db, 'COSTI_INDIRETTI');
        $patterns_costi_materie        = getContiByCategoria($db, 'COSTI_MATERIE_PRIME');
        $patterns_costi_lavorazioni    = getContiByCategoria($db, 'COSTI_LAVORAZIONI');
        $patterns_costi_servizi        = getContiByCategoria($db, 'COSTI_SERVIZI');
        $patterns_costi_personale      = getContiByCategoria($db, 'COSTI_PERSONALE');
        $patterns_oneri_sociali = getContiByCategoria($db, 'ONERI_SOCIALI');
        $patterns_oneri_vari = getContiByCategoria($db, 'ONERI_VARI');
        $patterns_debiti_fornitori     = getContiByCategoria($db, 'DEBITI_FORNITORI');
        $tutti_patterns_costi = array_merge(
            $patterns_costi_diretti, 
            $patterns_costi_indiretti, 
            $patterns_costi_materie, 
            $patterns_costi_lavorazioni, 
            $patterns_costi_personale,
            $patterns_oneri_sociali,  // ← NUOVO
            $patterns_costi_servizi,
            $patterns_oneri_vari      // ← NUOVO
        );      

        // WHERE clause (invariato: se vuoti -> 1=0)
        $where_ricavi = !empty($tutti_patterns_ricavi) ? buildWhereClauseConti($tutti_patterns_ricavi) : '1=0';
        $where_costi  = !empty($tutti_patterns_costi)  ? buildWhereClauseConti($tutti_patterns_costi)  : '1=0';

        // Range indicizzati (niente YEAR(col) in WHERE)
        $anno_start = sprintf('%04d-01-01', (int)$anno);
        $anno_next  = sprintf('%04d-01-01', (int)$anno + 1);

        // Query mensile per anno corrente (stessa logica campi)
        
        $where_crediti_clienti = !empty($patterns_crediti_clienti) ? buildWhereClauseConti($patterns_crediti_clienti) : '1=0';
        $where_debiti_fornitori = !empty($patterns_debiti_fornitori) ? buildWhereClauseConti($patterns_debiti_fornitori) : '1=0';
        $where_ricavi_corrispettivi = !empty($patterns_ricavi_corrispettivi) ? buildWhereClauseConti($patterns_ricavi_corrispettivi) : '1=0';
        
        $query_mensile = "
            SELECT 
                MONTH(data_registrazione) AS mese,
                COALESCE(SUM(CASE WHEN $where_ricavi THEN (avere - dare) ELSE 0 END), 0) AS ricavi,
                COALESCE(SUM(CASE WHEN $where_costi  THEN (dare  - avere) ELSE 0 END), 0) AS costi,
                COUNT(DISTINCT CASE 
                    WHEN ($where_crediti_clienti OR $where_ricavi_corrispettivi) 
                    THEN DATE(data_registrazione) 
                    ELSE NULL 
                END) AS num_transazioni_vendita,
                COUNT(DISTINCT CASE 
                    WHEN $where_debiti_fornitori 
                    THEN DATE(data_registrazione) 
                    ELSE NULL 
                END) AS num_transazioni_acquisto
            FROM libro_giornale
            WHERE data_registrazione >= ? AND data_registrazione < ?
            " . buildExcludeSaldiClause($db, 'entrambi') . "
            GROUP BY mese
            ORDER BY mese
        ";

        $stmt_mensile = $db->prepare($query_mensile);
        $stmt_mensile->execute([$anno_start, $anno_next]);

        // Nomi mesi in italiano (invariati)
        $nomi_mesi = [
            1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
            5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
            9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
        ];

        // Inizializzazione mesi (identica)
        $risultati_mensili = [];
        $totale_ricavi = 0.0;
        $totale_costi  = 0.0;

        for ($mese = 1; $mese <= 12; $mese++) {
            $risultati_mensili[$mese] = [
                'mese' => $mese,
                'nome_mese' => $nomi_mesi[$mese],
                'ricavi' => 0.00,
                'costi' => 0.00,
                'margine' => 0.00,
                'num_transazioni_vendita' => 0,
                'num_transazioni_acquisto' => 0,
                'ricavi_anno_precedente' => 0.00,
                'costi_anno_precedente' => 0.00,
                'variazione_ricavi' => 0.00,
                'variazione_costi' => 0.00,
                'percentuale_ricavi_annui' => 0.00,
                'percentuale_costi_annui' => 0.00
            ];
        }

        // Lettura anno corrente
        while ($row = $stmt_mensile->fetch(PDO::FETCH_ASSOC)) {
            $mese  = (int)$row['mese'];
            $ricavi= round(abs((float)$row['ricavi']), 2);
            $costi = round(abs((float)$row['costi']), 2);

            $risultati_mensili[$mese]['ricavi']  = $ricavi;
            $risultati_mensili[$mese]['costi']   = $costi;
            $risultati_mensili[$mese]['margine'] = round($ricavi - $costi, 2);
            $risultati_mensili[$mese]['num_transazioni_vendita']  = (int)$row['num_transazioni_vendita'];
            $risultati_mensili[$mese]['num_transazioni_acquisto'] = (int)$row['num_transazioni_acquisto'];

            $totale_ricavi += $ricavi;
            $totale_costi  += $costi;
        }

        // Anno precedente (stessa struttura, range indicizzato)
        if ($anno_precedente) {
            $anno_prec_start = sprintf('%04d-01-01', (int)$anno_precedente);
            $anno_prec_next  = sprintf('%04d-01-01', (int)$anno_precedente + 1);

            $query_mensile_prec = "
                SELECT 
                    MONTH(data_registrazione) AS mese,
                    COALESCE(SUM(CASE WHEN $where_ricavi THEN (avere - dare) ELSE 0 END), 0) AS ricavi,
                    COALESCE(SUM(CASE WHEN $where_costi  THEN (dare  - avere) ELSE 0 END), 0) AS costi
                FROM libro_giornale
                WHERE data_registrazione >= ? AND data_registrazione < ?
                " . buildExcludeSaldiClause($db, 'entrambi') . "
                GROUP BY mese
                ORDER BY mese
            ";

            $stmt_mensile_prec = $db->prepare($query_mensile_prec);
            $stmt_mensile_prec->execute([$anno_prec_start, $anno_prec_next]);

            while ($row = $stmt_mensile_prec->fetch(PDO::FETCH_ASSOC)) {
                $mese = (int)$row['mese'];
                if (isset($risultati_mensili[$mese])) {
                    $ricavi_prec = round(abs((float)$row['ricavi']), 2);
                    $costi_prec  = round(abs((float)$row['costi']), 2);

                    $risultati_mensili[$mese]['ricavi_anno_precedente'] = $ricavi_prec;
                    $risultati_mensili[$mese]['costi_anno_precedente']  = $costi_prec;

                    if ($ricavi_prec > 0) {
                        $risultati_mensili[$mese]['variazione_ricavi'] = round((($risultati_mensili[$mese]['ricavi'] - $ricavi_prec) / $ricavi_prec) * 100, 2);
                    }
                    if ($costi_prec > 0) {
                        $risultati_mensili[$mese]['variazione_costi'] = round((($risultati_mensili[$mese]['costi'] - $costi_prec) / $costi_prec) * 100, 2);
                    }
                }
            }
        }

        // Percentuali sul totale annuo (stessa logica)
        if ($totale_ricavi > 0) {
            foreach ($risultati_mensili as $mese => $dati) {
                $risultati_mensili[$mese]['percentuale_ricavi_annui'] = round(($dati['ricavi'] / $totale_ricavi) * 100, 2);
            }
        }
        if ($totale_costi > 0) {
            foreach ($risultati_mensili as $mese => $dati) {
                $risultati_mensili[$mese]['percentuale_costi_annui'] = round(($dati['costi'] / $totale_costi) * 100, 2);
            }
        }

        // Aggregazione trimestrale (invariata)
        $risultati_trimestrali = [
            1 => ['trimestre' => 1, 'nome' => 'Primo Trimestre',  'ricavi' => 0.00, 'costi' => 0.00, 'margine' => 0.00],
            2 => ['trimestre' => 2, 'nome' => 'Secondo Trimestre','ricavi' => 0.00, 'costi' => 0.00, 'margine' => 0.00],
            3 => ['trimestre' => 3, 'nome' => 'Terzo Trimestre',  'ricavi' => 0.00, 'costi' => 0.00, 'margine' => 0.00],
            4 => ['trimestre' => 4, 'nome' => 'Quarto Trimestre', 'ricavi' => 0.00, 'costi' => 0.00, 'margine' => 0.00]
        ];
        $mese_a_trimestre = [1=>1,2=>1,3=>1,4=>2,5=>2,6=>2,7=>3,8=>3,9=>3,10=>4,11=>4,12=>4];

        foreach ($risultati_mensili as $mese => $dati) {
            $tri = $mese_a_trimestre[$mese];
            $risultati_trimestrali[$tri]['ricavi']  += $dati['ricavi'];
            $risultati_trimestrali[$tri]['costi']   += $dati['costi'];
            $risultati_trimestrali[$tri]['margine'] += $dati['margine'];
        }
        foreach ($risultati_trimestrali as $tri => $d) {
            $risultati_trimestrali[$tri]['ricavi']  = round($d['ricavi'], 2);
            $risultati_trimestrali[$tri]['costi']   = round($d['costi'], 2);
            $risultati_trimestrali[$tri]['margine'] = round($d['margine'], 2);
            $risultati_trimestrali[$tri]['percentuale_ricavi_annui'] = $totale_ricavi > 0 ? round(($d['ricavi'] / $totale_ricavi) * 100, 2) : 0.00;
            $risultati_trimestrali[$tri]['percentuale_costi_annui']  = $totale_costi  > 0 ? round(($d['costi']  / $totale_costi)  * 100, 2) : 0.00;
        }

        // Picchi e minimi (invariati)
        $mese_picco_ricavi = 0; $valore_picco_ricavi = 0.0;
        $mese_minimo_ricavi = 0; $valore_minimo_ricavi = PHP_FLOAT_MAX;
        $trimestre_picco_ricavi = 0; $valore_trimestre_picco = 0.0;

        foreach ($risultati_mensili as $mese => $d) {
            if ($d['ricavi'] > $valore_picco_ricavi) {
                $mese_picco_ricavi = $mese;
                $valore_picco_ricavi = $d['ricavi'];
            }
            if ($d['ricavi'] < $valore_minimo_ricavi && $d['ricavi'] > 0) {
                $mese_minimo_ricavi = $mese;
                $valore_minimo_ricavi = $d['ricavi'];
            }
        }
        foreach ($risultati_trimestrali as $tri => $d) {
            if ($d['ricavi'] > $valore_trimestre_picco) {
                $trimestre_picco_ricavi = $tri;
                $valore_trimestre_picco = $d['ricavi'];
            }
        }

        // Indice di stagionalità (invariato)
        $percentuali_mensili = array_column($risultati_mensili, 'percentuale_ricavi_annui');
        $media_percentuale = count($percentuali_mensili) > 0 ? array_sum($percentuali_mensili) / count($percentuali_mensili) : 0;
        $somma_quadrati = array_reduce($percentuali_mensili, function($carry, $val) use ($media_percentuale) {
            return $carry + pow($val - $media_percentuale, 2);
        }, 0);
        $indice_stagionalita = count($percentuali_mensili) > 0 ? round(sqrt($somma_quadrati / count($percentuali_mensili)), 2) : 0;

        // Output identico
        return [
            'anno' => $anno,
            'anno_precedente' => $anno_precedente,
            'totale_ricavi' => round($totale_ricavi, 2),
            'totale_costi' => round($totale_costi, 2),
            'margine_totale' => round($totale_ricavi - $totale_costi, 2),
            'dati_mensili' => $risultati_mensili,
            'dati_trimestrali' => $risultati_trimestrali,
            'mese_picco_ricavi' => $mese_picco_ricavi,
            'nome_mese_picco' => $nomi_mesi[$mese_picco_ricavi] ?? 'N/A',
            'valore_picco_ricavi' => round($valore_picco_ricavi, 2),
            'mese_minimo_ricavi' => $mese_minimo_ricavi,
            'nome_mese_minimo' => $nomi_mesi[$mese_minimo_ricavi] ?? 'N/A',
            'valore_minimo_ricavi' => round($valore_minimo_ricavi, 2),
            'trimestre_picco_ricavi' => $trimestre_picco_ricavi,
            'nome_trimestre_picco' => $risultati_trimestrali[$trimestre_picco_ricavi]['nome'] ?? 'N/A',
            'valore_trimestre_picco' => round($valore_trimestre_picco, 2),
            'indice_stagionalita' => $indice_stagionalita,
            'rapporto_picco_minimo' => $valore_minimo_ricavi > 0 ? round($valore_picco_ricavi / $valore_minimo_ricavi, 2) : 0.00
        ];

    } catch (PDOException $e) {
        error_log("ERRORE Analisi Stagionalità: " . $e->getMessage());
        return [
            'errore' => $e->getMessage(),
            'anno' => $anno,
            'dati_mensili' => [],
            'dati_trimestrali' => []
        ];
    }
}

/**
 * Analisi Redditività Clienti con LOGICA SEQUENZIALE IDENTICA AL TEST
 * LOGICA TRASFERITA IDENTICAMENTE da test_clienti.php - ZERO MODIFICHE
 * Mantiene firma e struttura di ritorno identiche per compatibilità 
 */
function analisi_redditivita_clienti_giornale(PDO $pdo, $data_inizio, $data_fine, $periodo_confronto_inizio = null, $periodo_confronto_fine = null, $limite = 20) {
    // Calcolo periodo confronto (logica invariata)
    if (!$periodo_confronto_inizio || !$periodo_confronto_fine) {
        $durata = strtotime($data_fine) - strtotime($data_inizio);
        $periodo_confronto_fine = date('Y-m-d', strtotime($data_inizio) - 86400);
        $periodo_confronto_inizio = date('Y-m-d', strtotime($periodo_confronto_fine) - $durata);
    }

    // Recupero pattern conti - LOGICA DINAMICA CONFIGURABILE
    $patterns_ricavi_vendite = getContiByCategoria($pdo, 'RICAVI_VENDITE');
    $patterns_ricavi_prestazioni = getContiByCategoria($pdo, 'RICAVI_PRESTAZIONI');
    $patterns_crediti_clienti = getContiByCategoria($pdo, 'CREDITI_CLIENTI');
    
    // Unisco tutti i pattern dei RICAVI (solo quelli con protocolli)
    $tutti_patterns_ricavi = array_merge(
        $patterns_ricavi_vendite, 
        $patterns_ricavi_prestazioni
    );

    if (empty($tutti_patterns_ricavi) || empty($patterns_crediti_clienti)) {
        return [
            'periodo_corrente' => ['inizio' => $data_inizio, 'fine' => $data_fine],
            'periodo_confronto' => ['inizio' => $periodo_confronto_inizio, 'fine' => $periodo_confronto_fine],
            'totale_fatturato' => 0, 'totale_fatturato_precedente' => 0,
            'variazione_percentuale_totale' => 0, 'clienti' => [], 'totale_clienti_attivi' => 0
        ];
    }

    $where_ricavi = buildWhereClauseConti($tutti_patterns_ricavi);
    $where_crediti = buildWhereClauseConti($patterns_crediti_clienti);

    // 🧠 FUNZIONE PULIZIA NOME CLIENTE (IDENTICA AL TEST)
    $pulisci_nome_cliente = function($nome_originale) {
        $nome_cliente = trim($nome_originale);
        
        $patterns_da_rimuovere = [
            '/\s*-\s*fattura.*$/i',
            '/\s*-\s*fatt\.?.*$/i',
            '/\s*-\s*doc\.?.*$/i',
            '/\s*-\s*nr\.?.*$/i',
            '/\s*fattura.*$/i',
            '/\s*fatt\.?.*$/i',
            '/\s*doc\.?.*$/i',
            '/\s*nr\.?.*$/i',
            '/\s*del\s+\d{1,2}\/\d{1,2}\/\d{4}.*$/i',
            '/\s*\d{1,2}\/\d{1,2}\/\d{4}.*$/i',
            '/\s*n\.\s*\d+.*$/i',
            '/\s*#\d+.*$/i',
        ];
        
        foreach ($patterns_da_rimuovere as $pattern) {
            $nome_cliente = preg_replace($pattern, '', $nome_cliente);
        }
        
        $nome_cliente = trim($nome_cliente);
        $nome_cliente = preg_replace('/\s+/', ' ', $nome_cliente);
        $nome_cliente = rtrim($nome_cliente, ',');
        $nome_cliente = trim($nome_cliente);
        
        if (empty($nome_cliente) || strlen($nome_cliente) < 2) {
            if (preg_match('/^([a-zA-Z\s]{2,})/u', $nome_originale, $matches)) {
                $nome_cliente = trim($matches[1]);
            } else {
                $nome_cliente = 'Cliente Sconosciuto';
            }
        }
        
        return $nome_cliente;
    };

    // 🧠 FUNZIONE PER LEGGERE CLIENTE DAI CREDITI 
    $leggi_cliente_da_protocollo = function($protocollo) use ($pdo, $where_crediti) {
        $sql = "
            SELECT annotazioni 
            FROM libro_giornale 
            WHERE protocollo = ? 
              AND ($where_crediti)
              AND annotazioni IS NOT NULL
              AND TRIM(annotazioni) <> ''
              " . buildExcludeSaldiClause($pdo, 'entrambi') . "
            LIMIT 1
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$protocollo]);
        $result = $stmt->fetch();
        
        if ($result && !empty($result['annotazioni'])) {
            return $result['annotazioni'];
        }
        
        return null; // Nessun cliente trovato per questo protocollo
    };

    // Helper per elaborare periodo con LOGICA SEQUENZIALE IDENTICA AL TEST
    $elabora_periodo = function($dal, $al) use ($pdo, $where_ricavi, $pulisci_nome_cliente, $leggi_cliente_da_protocollo) {
        
        // 📊 STEP 1: LEGGI TUTTI I MOVIMENTI RICAVI (SENZA JOIN) - IDENTICO AL TEST
        $sql_ricavi = "
            SELECT protocollo, dare, avere, data_registrazione
            FROM libro_giornale 
            WHERE data_registrazione BETWEEN ? AND ?
              AND ($where_ricavi)
              AND (dare > 0 OR avere > 0)
              AND protocollo IS NOT NULL 
              AND TRIM(protocollo) <> ''
              " . buildExcludeSaldiClause($pdo, 'entrambi') . "
            ORDER BY data_registrazione, protocollo
        ";

        $stmt_ricavi = $pdo->prepare($sql_ricavi);
        $stmt_ricavi->execute([$dal, $al]);
        $movimenti_ricavi = $stmt_ricavi->fetchAll();

        // 🎯 STEP 2: AGGREGAZIONE CLIENTI SEQUENZIALE (IDENTICA AL TEST)
        $clienti_aggregati = [];
        $protocolli_processati = 0;
        $protocolli_con_cliente = 0;
        $protocolli_senza_cliente = 0;

        foreach ($movimenti_ricavi as $movimento) {
            $protocollo = $movimento['protocollo'];
            $dare = (float)$movimento['dare'];
            $avere = (float)$movimento['avere'];
            $data_reg = $movimento['data_registrazione'];
            
            $protocolli_processati++;
            
            // STEP 3: VAI A LEGGERE IL CLIENTE DAI CREDITI (IDENTICO AL TEST)
            $nome_cliente_raw = $leggi_cliente_da_protocollo($protocollo);
            
            if ($nome_cliente_raw === null) {
                $protocolli_senza_cliente++;
                continue; // Salta questo movimento se non ha cliente
            }
            
            $protocolli_con_cliente++;
            
            // PULIZIA NOME CLIENTE (IDENTICA AL TEST)
            $nome_cliente = $pulisci_nome_cliente($nome_cliente_raw);
            
            // Inizializza cliente se non esiste
            if (!isset($clienti_aggregati[$nome_cliente])) {
                $clienti_aggregati[$nome_cliente] = [
                    'nome_cliente' => $nome_cliente,
                    'fatturato_lordo' => 0,
                    'note_credito' => 0,
                    'num_fatture' => 0,
                    'num_nc' => 0,
                    'prima_data' => $data_reg,
                    'ultima_data' => $data_reg,
                    'protocolli_fatture' => [],
                    'protocolli_nc' => []
                ];
            }
            
            // STEP 4: CLASSIFICA IL MOVIMENTO (IDENTICO AL TEST)
            if ($avere > 0) {
                // FATTURA (avere positivo nei ricavi)
                $clienti_aggregati[$nome_cliente]['fatturato_lordo'] += $avere;
                $clienti_aggregati[$nome_cliente]['num_fatture']++;
                $clienti_aggregati[$nome_cliente]['protocolli_fatture'][] = $protocollo;
                
            } elseif ($avere < 0 || $dare > 0) {
                // NOTA DI CREDITO (avere negativo O dare positivo nei ricavi)
                $importo_nc = ($avere < 0) ? abs($avere) : $dare;
                $clienti_aggregati[$nome_cliente]['note_credito'] += $importo_nc;
                $clienti_aggregati[$nome_cliente]['num_nc']++;
                $clienti_aggregati[$nome_cliente]['protocolli_nc'][] = $protocollo;
            }
            
            // Aggiorna date
            if ($data_reg < $clienti_aggregati[$nome_cliente]['prima_data']) {
                $clienti_aggregati[$nome_cliente]['prima_data'] = $data_reg;
            }
            if ($data_reg > $clienti_aggregati[$nome_cliente]['ultima_data']) {
                $clienti_aggregati[$nome_cliente]['ultima_data'] = $data_reg;
            }
        }

        // 🎯 STEP 5: CALCOLA FATTURATO NETTO E FILTRA (IDENTICO AL TEST)
        $clienti_finali = [];
        foreach ($clienti_aggregati as $nome_cliente => $dati) {
            $fatturato_netto = $dati['fatturato_lordo'] - $dati['note_credito'];
            
            if ($fatturato_netto > 0) { // Solo clienti con fatturato netto positivo
                // ADATTO AL FORMAT RICHIESTO DALLA FUNZIONE
                $clienti_finali[$nome_cliente] = [
                    'cliente' => $nome_cliente,
                    'fatturato_totale' => $fatturato_netto,  // <- NETTO come richiesto
                    'fatturato_lordo' => $dati['fatturato_lordo'],
                    'note_credito' => $dati['note_credito'],
                    'num_fatture' => $dati['num_fatture'],
                    'num_nc' => $dati['num_nc'],
                    'prima_fattura' => $dati['prima_data'],
                    'ultima_fattura' => $dati['ultima_data']
                ];
            }
        }

        return $clienti_finali;
    };

    // Elaborazione periodi (logica invariata)
    $clienti_corrente = $elabora_periodo($data_inizio, $data_fine);
    $clienti_confronto = $elabora_periodo($periodo_confronto_inizio, $periodo_confronto_fine);

    // Calcolo variazioni (logica invariata)
    $totale_fatturato = 0.0;
    $totale_precedente = 0.0;

    foreach ($clienti_corrente as $codice => &$c) {
        $prec = isset($clienti_confronto[$codice]) ? (float)$clienti_confronto[$codice]['fatturato_totale'] : 0.0;
        $c['fatturato_periodo_precedente'] = $prec;

        if ($prec > 0.0) {
            $var = (($c['fatturato_totale'] - $prec) / $prec) * 100;
            $c['variazione_percentuale'] = round($var, 2);
            $c['trend'] = $var > 10 ? 'in_crescita' : ($var < -10 ? 'in_calo' : 'stabile');
        } else {
            $c['variazione_percentuale'] = 100.0;
            $c['trend'] = 'nuovo';
        }

        $totale_fatturato += $c['fatturato_totale'];
        $totale_precedente += $prec;
    }
    unset($c);

    $variazione_percentuale_totale = $totale_precedente > 0
        ? round((($totale_fatturato - $totale_precedente) / $totale_precedente) * 100, 2)
        : 0.0;

    // Ordina e limita (logica invariata)
    $clienti_array = array_values($clienti_corrente);
    usort($clienti_array, function ($a, $b) {
        return $b['fatturato_totale'] <=> $a['fatturato_totale'];
    });
    $clienti_top = array_slice($clienti_array, 0, $limite);

    // STRUTTURA DI RITORNO INVARIATA (compatibilità totale)
    return [
        'periodo_corrente' => ['inizio' => $data_inizio, 'fine' => $data_fine],
        'periodo_confronto' => ['inizio' => $periodo_confronto_inizio, 'fine' => $periodo_confronto_fine],
        'totale_fatturato' => $totale_fatturato,
        'totale_fatturato_precedente' => $totale_precedente,
        'variazione_percentuale_totale' => $variazione_percentuale_totale,
        'clienti' => $clienti_top,
        'totale_clienti_attivi' => count($clienti_corrente)
    ];
}

/**
 * Analisi ABC fornitori 
 */
function analisi_abc_fornitori_giornale($db, $data_inizio, $data_fine, $periodo_confronto_inizio = null, $periodo_confronto_fine = null) {
    
    // Validazione parametri
    if (strtotime($data_inizio) > strtotime($data_fine)) {
        return get_empty_fornitori_result();
    }
    
    // Calcolo periodo confronto (logica invariata)
    if ($periodo_confronto_inizio === null || $periodo_confronto_fine === null) {
        $durata_periodo = strtotime($data_fine) - strtotime($data_inizio);
        $periodo_confronto_fine = date('Y-m-d', strtotime($data_inizio) - 86400);
        $periodo_confronto_inizio = date('Y-m-d', strtotime($periodo_confronto_fine) - $durata_periodo);
    }
    
    // Recupero pattern conti - LOGICA DINAMICA CONFIGURABILE
    $patterns_costi_diretti = getContiByCategoria($db, 'COSTI_DIRETTI');
    $patterns_costi_materie = getContiByCategoria($db, 'COSTI_MATERIE_PRIME');
    $patterns_costi_lavorazioni = getContiByCategoria($db, 'COSTI_LAVORAZIONI');
    $patterns_costi_indiretti = getContiByCategoria($db, 'COSTI_INDIRETTI');
    $patterns_costi_servizi = getContiByCategoria($db, 'COSTI_SERVIZI');
    $patterns_debiti_fornitori = getContiByCategoria($db, 'DEBITI_FORNITORI');

    // Unisco tutti i pattern dei COSTI
    $tutti_patterns_costi = array_merge(
        $patterns_costi_diretti, 
        $patterns_costi_materie,
        $patterns_costi_lavorazioni,
        $patterns_costi_indiretti,
        $patterns_costi_servizi
    );

    if (empty($tutti_patterns_costi) || empty($patterns_debiti_fornitori)) {
        return get_empty_fornitori_result();
    }

    $where_costi = buildWhereClauseConti($tutti_patterns_costi);
    $where_debiti = buildWhereClauseConti($patterns_debiti_fornitori);

    // FUNZIONE PULIZIA NOME FORNITORE (IDENTICA AL TEST)
    $pulisci_nome_fornitore = function($nome_originale) {
        $nome_fornitore = trim($nome_originale);
        
        $patterns_da_rimuovere = [
            '/\s*-\s*fattura.*$/i',
            '/\s*-\s*fatt\.?.*$/i',
            '/\s*-\s*doc\.?.*$/i',
            '/\s*-\s*nr\.?.*$/i',
            '/\s*fattura.*$/i',
            '/\s*fatt\.?.*$/i',
            '/\s*doc\.?.*$/i',
            '/\s*nr\.?.*$/i',
            '/\s*del\s+\d{1,2}\/\d{1,2}\/\d{4}.*$/i',
            '/\s*\d{1,2}\/\d{1,2}\/\d{4}.*$/i',
            '/\s*n\.\s*\d+.*$/i',
            '/\s*#\d+.*$/i',
        ];
        
        foreach ($patterns_da_rimuovere as $pattern) {
            $nome_fornitore = preg_replace($pattern, '', $nome_fornitore);
        }
        
        $nome_fornitore = trim($nome_fornitore);
        $nome_fornitore = preg_replace('/\s+/', ' ', $nome_fornitore);
        $nome_fornitore = rtrim($nome_fornitore, ',');
        $nome_fornitore = trim($nome_fornitore);
        
        if (empty($nome_fornitore) || strlen($nome_fornitore) < 2) {
            if (preg_match('/^([a-zA-Z\s]{2,})/u', $nome_originale, $matches)) {
                $nome_fornitore = trim($matches[1]);
            } else {
                $nome_fornitore = 'Fornitore Sconosciuto';
            }
        }
        
        return $nome_fornitore;
    };

    // FUNZIONE PER LEGGERE FORNITORE DAI DEBITI
    $leggi_fornitore_da_protocollo = function($protocollo) use ($db, $where_debiti) {
        $sql = "
            SELECT annotazioni 
            FROM libro_giornale 
            WHERE protocollo = ? 
              AND ($where_debiti)
              AND annotazioni IS NOT NULL
              AND TRIM(annotazioni) <> ''
              " . buildExcludeSaldiClause($db, 'entrambi') . "
            LIMIT 1
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$protocollo]);
        $result = $stmt->fetch();
        
        if ($result && !empty($result['annotazioni'])) {
            return $result['annotazioni'];
        }
        
        return null; // Nessun fornitore trovato per questo protocollo
    };

    try {
        // Helper per elaborare periodo con LOGICA SEQUENZIALE IDENTICA AL TEST
        $elabora_periodo = function($dal, $al) use ($db, $where_costi, $pulisci_nome_fornitore, $leggi_fornitore_da_protocollo) {
            
            // 📊 STEP 1: LEGGI TUTTI I MOVIMENTI COSTI (SENZA JOIN)
            $sql_costi = "
                SELECT protocollo, dare, avere, data_registrazione
                FROM libro_giornale 
                WHERE data_registrazione BETWEEN ? AND ?
                  AND ($where_costi)
                  AND (dare > 0 OR avere > 0)
                  AND protocollo IS NOT NULL 
                  AND TRIM(protocollo) <> ''
                  " . buildExcludeSaldiClause($db, 'entrambi') . "
                ORDER BY data_registrazione, protocollo
            ";

            $stmt_costi = $db->prepare($sql_costi);
            $stmt_costi->execute([$dal, $al]);
            $movimenti_costi = $stmt_costi->fetchAll();

            // 🎯 STEP 2: AGGREGAZIONE FORNITORI SEQUENZIALE (IDENTICA AL TEST)
            $fornitori_aggregati = [];

            foreach ($movimenti_costi as $movimento) {
                $protocollo = $movimento['protocollo'];
                $dare = (float)$movimento['dare'];
                $avere = (float)$movimento['avere'];
                $data_reg = $movimento['data_registrazione'];
                
                // STEP 3: VAI A LEGGERE IL FORNITORE DAI DEBITI (IDENTICO AL TEST)
                $nome_fornitore_raw = $leggi_fornitore_da_protocollo($protocollo);
                
                if ($nome_fornitore_raw === null) {
                    continue; // Salta questo movimento se non ha fornitore
                }
                
                // PULIZIA NOME FORNITORE (IDENTICA AL TEST)
                $nome_fornitore = $pulisci_nome_fornitore($nome_fornitore_raw);
                
                // Inizializza fornitore se non esiste
                if (!isset($fornitori_aggregati[$nome_fornitore])) {
                    $fornitori_aggregati[$nome_fornitore] = [
                        'nome_fornitore' => $nome_fornitore,
                        'spesa_lorda' => 0,
                        'note_credito' => 0,
                        'num_fatture' => 0,
                        'num_nc' => 0,
                        'prima_data' => $data_reg,
                        'ultima_data' => $data_reg,
                        'protocolli_fatture' => [],
                        'protocolli_nc' => []
                    ];
                }
                
                // STEP 4: CLASSIFICA IL MOVIMENTO (LOGICA INVERTITA RISPETTO CLIENTI)
                if ($dare > 0) {
                    // SPESA (dare positivo nei costi)
                    $fornitori_aggregati[$nome_fornitore]['spesa_lorda'] += $dare;
                    $fornitori_aggregati[$nome_fornitore]['num_fatture']++;
                    $fornitori_aggregati[$nome_fornitore]['protocolli_fatture'][] = $protocollo;
                    
                } elseif ($dare < 0 || $avere > 0) {
                    // NOTA DI CREDITO (dare negativo O avere positivo nei costi)
                    $importo_nc = ($dare < 0) ? abs($dare) : $avere;
                    $fornitori_aggregati[$nome_fornitore]['note_credito'] += $importo_nc;
                    $fornitori_aggregati[$nome_fornitore]['num_nc']++;
                    $fornitori_aggregati[$nome_fornitore]['protocolli_nc'][] = $protocollo;
                }
                
                // Aggiorna date
                if ($data_reg < $fornitori_aggregati[$nome_fornitore]['prima_data']) {
                    $fornitori_aggregati[$nome_fornitore]['prima_data'] = $data_reg;
                }
                if ($data_reg > $fornitori_aggregati[$nome_fornitore]['ultima_data']) {
                    $fornitori_aggregati[$nome_fornitore]['ultima_data'] = $data_reg;
                }
            }

            // 🎯 STEP 5: CALCOLA SPESA NETTA E FILTRA (IDENTICO AL TEST)
            $fornitori_finali = [];
            foreach ($fornitori_aggregati as $nome_fornitore => $dati) {
                $spesa_netta = $dati['spesa_lorda'] - $dati['note_credito'];
                
                if ($spesa_netta > 0) { // Solo fornitori con spesa netta positiva
                    // ADATTO AL FORMAT RICHIESTO DALLA FUNZIONE
                    $fornitori_finali[$nome_fornitore] = [
                        'fornitore' => $nome_fornitore,
                        'spesa_totale' => $spesa_netta,  // <- NETTO come richiesto
                        'spesa_lorda' => $dati['spesa_lorda'],
                        'note_credito' => $dati['note_credito'],
                        'num_fatture' => $dati['num_fatture'],
                        'num_nc' => $dati['num_nc'],
                        'prima_fattura' => $dati['prima_data'],
                        'ultima_fattura' => $dati['ultima_data']
                    ];
                }
            }

            return $fornitori_finali;
        };
        

        // Elaborazione periodi (logica invariata)
        $fornitori_corrente = $elabora_periodo($data_inizio, $data_fine);
        $fornitori_confronto = $elabora_periodo($periodo_confronto_inizio, $periodo_confronto_fine);

        // Calcolo variazioni (logica invariata)
        $totale_nuovi_clienti = 0;
        $totale_clienti_persi = 0;

        foreach ($fornitori_corrente as $codice => &$f) {
            $prec = isset($fornitori_confronto[$codice]) ? (float)$fornitori_confronto[$codice]['spesa_totale'] : 0.0;
            $f['spesa_periodo_precedente'] = $prec;

            if ($prec > 0.0) {
                $var = (($f['spesa_totale'] - $prec) / $prec) * 100;
                $f['variazione_percentuale'] = round($var, 2);
                $f['trend'] = $var > 10 ? 'in_crescita' : ($var < -10 ? 'in_calo' : 'stabile');
            } else {
                $f['variazione_percentuale'] = 100.0;
                $f['trend'] = 'nuovo';
                $totale_nuovi_clienti++;
            }
        }
        unset($f);
        
        // Calcola totali
        $spesa_totale = round(array_sum(array_column($fornitori_corrente, 'spesa_totale')), 2);
        $spesa_totale_precedente = round(array_sum(array_column($fornitori_confronto, 'spesa_totale')), 2);

        // Calcola variazione percentuale totale
        $variazione_percentuale_totale = $spesa_totale_precedente > 0
            ? round((($spesa_totale - $spesa_totale_precedente) / $spesa_totale_precedente) * 100, 2)
            : 0.00;

        // Ordina per spesa totale DECRESCENTE
        $fornitori_array = array_values($fornitori_corrente);
        usort($fornitori_array, function($a, $b) {
            return $b['spesa_totale'] <=> $a['spesa_totale'];
        });

        // CLASSIFICAZIONE ABC (logica invariata)
        $categoria_a = [];
        $categoria_b = [];
        $categoria_c = [];
        $cumulato_percentuale = 0;

        foreach ($fornitori_array as $i => &$f) {
            $percentuale = $spesa_totale > 0 ? ($f['spesa_totale'] / $spesa_totale) * 100 : 0;
            $cumulato_percentuale += $percentuale;

            if ($cumulato_percentuale <= 80) {
                $categoria = 'A';
                $categoria_a[] = &$f;
            } elseif ($cumulato_percentuale <= 95) {
                $categoria = 'B';
                $categoria_b[] = &$f;
            } else {
                $categoria = 'C';
                $categoria_c[] = &$f;
            }

            $f['categoria'] = $categoria;
            $f['percentuale_sul_totale'] = round($percentuale, 2);
            $f['percentuale_cumulativa'] = round($cumulato_percentuale, 2);
            
        }
        unset($f);
        
        // CONTEGGIO FORNITORI AGGREGATI (dopo la classificazione ABC)
        $num_fornitori_a = count($categoria_a);
        $num_fornitori_b = count($categoria_b);  
        $num_fornitori_c = count($categoria_c);
        $totale_fornitori_aggregati = count($fornitori_array);

        return [
            'periodo_corrente' => [
                'inizio' => $data_inizio,
                'fine' => $data_fine
            ],
            'periodo_confronto' => [
                'inizio' => $periodo_confronto_inizio,
                'fine' => $periodo_confronto_fine
            ],
            'fornitori' => $fornitori_array,
            'spesa_totale' => $spesa_totale,
            'spesa_totale_precedente' => $spesa_totale_precedente,
            'variazione_percentuale_totale' => $variazione_percentuale_totale,
            'spesa_categoria_a' => round(array_sum(array_column($categoria_a, 'spesa_totale')), 2),
            'spesa_categoria_b' => round(array_sum(array_column($categoria_b, 'spesa_totale')), 2),
            'spesa_categoria_c' => round(array_sum(array_column($categoria_c, 'spesa_totale')), 2),
            'percentuale_categoria_a' => $spesa_totale > 0 ? round((array_sum(array_column($categoria_a, 'spesa_totale')) / $spesa_totale) * 100, 2) : 0.00,
            'percentuale_categoria_b' => $spesa_totale > 0 ? round((array_sum(array_column($categoria_b, 'spesa_totale')) / $spesa_totale) * 100, 2) : 0.00,
            'percentuale_categoria_c' => $spesa_totale > 0 ? round((array_sum(array_column($categoria_c, 'spesa_totale')) / $spesa_totale) * 100, 2) : 0.00,
            'num_fornitori_a' => $num_fornitori_a,
            'num_fornitori_b' => $num_fornitori_b,
            'num_fornitori_c' => $num_fornitori_c,
            'totale_fornitori_aggregati' => $totale_fornitori_aggregati,
            'fornitori_in_aumento' => count(array_filter($fornitori_array, function($f) { return $f['trend'] == 'in_crescita'; })),
            'percentuale_fornitori_aumento' => count($fornitori_array) > 0 ? round((count(array_filter($fornitori_array, function($f) { return $f['trend'] == 'in_crescita'; })) / count($fornitori_array)) * 100, 2) : 0.00,
            'spesa_aumento' => round(array_sum(array_map(function($f) { return $f['trend'] == 'in_crescita' ? $f['spesa_totale'] : 0; }, $fornitori_array)), 2),
            'percentuale_spesa_aumento' => $spesa_totale > 0 ? round((array_sum(array_map(function($f) { return $f['trend'] == 'in_crescita' ? $f['spesa_totale'] : 0; }, $fornitori_array)) / $spesa_totale) * 100, 2) : 0.00
        ];
        
    } catch (PDOException $e) {
        error_log("Errore nell'analisi ABC fornitori: " . $e->getMessage());
        return array_merge(get_empty_fornitori_result(), ['errore' => $e->getMessage()]);
    }
}

/**
 * Restituisce un array vuoto per i risultati fornitori (funzione helper esistente)
 */
function get_empty_fornitori_result() {
    return [
        'periodo_corrente' => ['inizio' => '', 'fine' => ''],
        'periodo_confronto' => ['inizio' => '', 'fine' => ''],
        'fornitori' => [],
        'spesa_totale' => 0.00,
        'spesa_totale_precedente' => 0.00,
        'variazione_percentuale_totale' => 0.00,
        'spesa_categoria_a' => 0.00,
        'spesa_categoria_b' => 0.00,
        'spesa_categoria_c' => 0.00,
        'percentuale_categoria_a' => 0.00,
        'percentuale_categoria_b' => 0.00,
        'percentuale_categoria_c' => 0.00,
        'num_fornitori_a' => 0,
        'num_fornitori_b' => 0,
        'num_fornitori_c' => 0,
    ];
}

function calcola_indici_crescita_giornale($db, $data_fine, $mesi_analisi = 12) {
    // Calcola data di inizio (n mesi prima della data di fine)
    $data_inizio = date('Y-m-d', strtotime("-$mesi_analisi months", strtotime($data_fine)));

    try {
        // Patterns contabili (stessa logica/nomi)
        $patterns_ricavi_vendite      = getContiByCategoria($db, 'RICAVI_VENDITE');
        $patterns_ricavi_prestazioni  = getContiByCategoria($db, 'RICAVI_PRESTAZIONI');
        $patterns_ricavi_corrispettivi= getContiByCategoria($db, 'RICAVI_CORRISPETTIVI');
        $tutti_patterns_ricavi        = array_merge($patterns_ricavi_vendite, $patterns_ricavi_prestazioni, $patterns_ricavi_corrispettivi);

        $patterns_costi_diretti       = getContiByCategoria($db, 'COSTI_DIRETTI');
        $patterns_costi_indiretti     = getContiByCategoria($db, 'COSTI_INDIRETTI');
        $patterns_costi_materie       = getContiByCategoria($db, 'COSTI_MATERIE_PRIME');
        $patterns_costi_lavorazioni   = getContiByCategoria($db, 'COSTI_LAVORAZIONI');
        $patterns_costi_servizi       = getContiByCategoria($db, 'COSTI_SERVIZI');
        $patterns_costi_personale     = getContiByCategoria($db, 'COSTI_PERSONALE');
        $patterns_oneri_sociali       = getContiByCategoria($db, 'ONERI_SOCIALI');
        $patterns_oneri_vari          = getContiByCategoria($db, 'ONERI_VARI');
        $tutti_patterns_costi = array_merge(
            $patterns_costi_diretti, 
            $patterns_costi_indiretti, 
            $patterns_costi_materie, 
            $patterns_costi_lavorazioni, 
            $patterns_costi_servizi, 
            $patterns_costi_personale,
            $patterns_oneri_sociali,  // ← NUOVO
            $patterns_oneri_vari      // ← NUOVO
        );

        // WHERE clause (identico comportamento: se vuoto -> 1=0)
        $where_ricavi = !empty($tutti_patterns_ricavi) ? buildWhereClauseConti($tutti_patterns_ricavi) : '1=0';
        $where_costi  = !empty($tutti_patterns_costi)  ? buildWhereClauseConti($tutti_patterns_costi)  : '1=0';

        // Base mensile con window functions per valori del mese precedente e dell'anno precedente
        $sql = "
            WITH mensili AS (
                SELECT 
                    DATE_FORMAT(data_registrazione, '%Y-%m')      AS periodo,
                    DATE_FORMAT(data_registrazione, '%Y-%m-01')   AS periodo_data,
                    YEAR(data_registrazione)                      AS anno,
                    MONTH(data_registrazione)                     AS mese,
                    SUM(CASE WHEN $where_ricavi THEN (avere - dare) ELSE 0 END) AS ricavi_raw,
                    SUM(CASE WHEN $where_costi  THEN (dare  - avere) ELSE 0 END) AS costi_raw,
                    COUNT(DISTINCT CASE WHEN $where_ricavi AND avere > 0 THEN protocollo END) AS num_transazioni_vendita,
                    COUNT(DISTINCT CASE WHEN $where_costi  AND dare  > 0 THEN protocollo END) AS num_transazioni_acquisto
                FROM libro_giornale
                WHERE data_registrazione BETWEEN ? AND ?
                " . buildExcludeSaldiClause($db, 'entrambi') . "
                GROUP BY periodo, periodo_data, anno, mese
            ),
            arr AS (
                SELECT
                    periodo,
                    periodo_data,
                    anno,
                    mese,
                    ABS(ricavi_raw) AS ricavi,
                    ABS(costi_raw)  AS costi,
                    (ABS(ricavi_raw) - ABS(costi_raw)) AS margine,
                    num_transazioni_vendita,
                    num_transazioni_acquisto
                FROM mensili
            )
            SELECT
                periodo,
                periodo_data,
                anno,
                mese,
                ricavi,
                costi,
                margine,
                num_transazioni_vendita,
                num_transazioni_acquisto,
                LAG(ricavi,  1)  OVER (ORDER BY periodo_data) AS ricavi_prev_mese,
                LAG(costi,   1)  OVER (ORDER BY periodo_data) AS costi_prev_mese,
                LAG(margine, 1)  OVER (ORDER BY periodo_data) AS margine_prev_mese,
                LAG(ricavi,  12) OVER (ORDER BY periodo_data) AS ricavi_prev_anno,
                LAG(costi,   12) OVER (ORDER BY periodo_data) AS costi_prev_anno,
                LAG(margine, 12) OVER (ORDER BY periodo_data) AS margine_prev_anno
            FROM arr
            ORDER BY periodo_data
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$data_inizio, $data_fine]);

        $risultati_mensili = [];
        $periodi_unici = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $periodo = $row['periodo'];
            $periodi_unici[] = $periodo;

            $ricavi = isset($row['ricavi']) ? (float)$row['ricavi'] : 0.0;
            $costi  = isset($row['costi'])  ? (float)$row['costi']  : 0.0;
            $margine= isset($row['margine'])? (float)$row['margine']: ($ricavi - $costi);

            $num_tx_v = (int)$row['num_transazioni_vendita'];
            $num_tx_a = (int)$row['num_transazioni_acquisto'];

            $risultati_mensili[$periodo] = [
                'periodo' => $periodo,
                'anno' => (int)$row['anno'],
                'mese' => (int)$row['mese'],
                'nome_mese' => date('F', mktime(0, 0, 0, (int)$row['mese'], 1, (int)$row['anno'])),
                'ricavi' => $ricavi,
                'costi' => $costi,
                'margine' => $margine,
                'num_transazioni_vendita' => $num_tx_v,
                'num_transazioni_acquisto' => $num_tx_a,
                'valore_medio_transazione' => $num_tx_v > 0 ? ($ricavi / $num_tx_v) : 0,
                'margine_percentuale' => $ricavi > 0 ? (($margine) / $ricavi) * 100 : 0,
                // Placeholder: saranno riempiti sotto mantenendo la logica originale
                'crescita_ricavi_mom' => 0,
                'crescita_costi_mom' => 0,
                'crescita_margine_mom' => 0,
                'crescita_transazioni_mom' => 0,
                'crescita_ricavi_yoy' => null,
                'crescita_costi_yoy' => null,
                'crescita_margine_yoy' => null,
                // Metto anche i prev per evitare ricerche tra array
                '_ricavi_prev_mese' => isset($row['ricavi_prev_mese']) ? (float)$row['ricavi_prev_mese'] : null,
                '_costi_prev_mese'  => isset($row['costi_prev_mese'])  ? (float)$row['costi_prev_mese']  : null,
                '_margine_prev_mese'=> isset($row['margine_prev_mese'])? (float)$row['margine_prev_mese']: null,
                '_ricavi_prev_anno' => isset($row['ricavi_prev_anno']) ? (float)$row['ricavi_prev_anno'] : null,
                '_costi_prev_anno'  => isset($row['costi_prev_anno'])  ? (float)$row['costi_prev_anno']  : null,
                '_margine_prev_anno'=> isset($row['margine_prev_anno'])? (float)$row['margine_prev_anno']: null,
            ];
        }

        // Nessun periodo? ritorna struttura vuota (identico comportamento)
        if (empty($periodi_unici)) {
            return [
                'periodi_analizzati' => 0,
                'dati_mensili' => [],
                'dati_trimestrali' => [],
                'dati_annuali' => [],
                'indici_crescita' => []
            ];
        }

        // Calcolo MoM — stessa logica/edge-case dell’originale
        foreach ($periodi_unici as $idx => $periodo) {
            $d = &$risultati_mensili[$periodo];
            // Ricavi MoM
            if ($idx > 0) {
                $prev_ricavi = $d['_ricavi_prev_mese'];
                if ($prev_ricavi !== null && $prev_ricavi > 0) {
                    $d['crescita_ricavi_mom'] = (($d['ricavi'] - $prev_ricavi) / $prev_ricavi) * 100;
                } else {
                    $d['crescita_ricavi_mom'] = ($d['ricavi'] > 0) ? 100 : 0;
                }

                // Costi MoM
                $prev_costi = $d['_costi_prev_mese'];
                if ($prev_costi !== null && $prev_costi > 0) {
                    $d['crescita_costi_mom'] = (($d['costi'] - $prev_costi) / $prev_costi) * 100;
                } else {
                    $d['crescita_costi_mom'] = ($d['costi'] > 0) ? 100 : 0;
                }

                // Margine MoM
                $prev_margine = $d['_margine_prev_mese'];
                if ($prev_margine !== null && abs($prev_margine) > 0) {
                    $d['crescita_margine_mom'] = (($d['margine'] - $prev_margine) / abs($prev_margine)) * 100;
                } else {
                    $d['crescita_margine_mom'] = $d['margine'] > 0 ? 100 : ($d['margine'] < 0 ? -100 : 0);
                }

                // Transazioni MoM (vendita)
                $prev_tx = ($idx > 0) ? $risultati_mensili[$periodi_unici[$idx - 1]]['num_transazioni_vendita'] : 0;
                if ($prev_tx > 0) {
                    $d['crescita_transazioni_mom'] = (($d['num_transazioni_vendita'] - $prev_tx) / $prev_tx) * 100;
                } else {
                    $d['crescita_transazioni_mom'] = ($d['num_transazioni_vendita'] > 0) ? 100 : 0;
                }
            } else {
                // primo periodo
                $d['crescita_ricavi_mom'] = 0;
                $d['crescita_costi_mom'] = 0;
                $d['crescita_margine_mom'] = 0;
                $d['crescita_transazioni_mom'] = 0;
            }
            unset($d);
        }

        // Calcolo YoY — stessa logica/edge-case dell’originale
        foreach ($periodi_unici as $periodo) {
            $d = &$risultati_mensili[$periodo];
            $prev_anno_ricavi  = $d['_ricavi_prev_anno'];
            $prev_anno_costi   = $d['_costi_prev_anno'];
            $prev_anno_margine = $d['_margine_prev_anno'];

            if ($prev_anno_ricavi !== null) {
                if ($prev_anno_ricavi > 0) {
                    $d['crescita_ricavi_yoy'] = (($d['ricavi'] - $prev_anno_ricavi) / $prev_anno_ricavi) * 100;
                } else {
                    $d['crescita_ricavi_yoy'] = ($d['ricavi'] > 0) ? 100 : 0;
                }
            } else {
                $d['crescita_ricavi_yoy'] = null;
            }

            if ($prev_anno_costi !== null) {
                if ($prev_anno_costi > 0) {
                    $d['crescita_costi_yoy'] = (($d['costi'] - $prev_anno_costi) / $prev_anno_costi) * 100;
                } else {
                    $d['crescita_costi_yoy'] = ($d['costi'] > 0) ? 100 : 0;
                }
            } else {
                $d['crescita_costi_yoy'] = null;
            }

            if ($prev_anno_margine !== null) {
                if (abs($prev_anno_margine) > 0) {
                    $d['crescita_margine_yoy'] = (($d['margine'] - $prev_anno_margine) / abs($prev_anno_margine)) * 100;
                } else {
                    $d['crescita_margine_yoy'] = $d['margine'] > 0 ? 100 : ($d['margine'] < 0 ? -100 : 0);
                }
            } else {
                $d['crescita_margine_yoy'] = null;
            }
            unset($d);
        }

        // Aggregazione trimestrale (identica alla tua logica)
        $dati_trimestrali = [];
        foreach ($risultati_mensili as $periodo => $dati) {
            $anno = $dati['anno'];
            $mese = $dati['mese'];
            $trimestre = (int)ceil($mese / 3);
            $periodo_trimestrale = sprintf("%04d-Q%d", $anno, $trimestre);

            if (!isset($dati_trimestrali[$periodo_trimestrale])) {
                $dati_trimestrali[$periodo_trimestrale] = [
                    'periodo' => $periodo_trimestrale,
                    'anno' => $anno,
                    'trimestre' => $trimestre,
                    'ricavi' => 0,
                    'costi' => 0,
                    'margine' => 0,
                    'num_transazioni_vendita' => 0,
                    'num_transazioni_acquisto' => 0
                ];
            }

            $dati_trimestrali[$periodo_trimestrale]['ricavi'] += $dati['ricavi'];
            $dati_trimestrali[$periodo_trimestrale]['costi']  += $dati['costi'];
            $dati_trimestrali[$periodo_trimestrale]['margine']+= $dati['margine'];
            $dati_trimestrali[$periodo_trimestrale]['num_transazioni_vendita'] += $dati['num_transazioni_vendita'];
            $dati_trimestrali[$periodo_trimestrale]['num_transazioni_acquisto'] += $dati['num_transazioni_acquisto'];
        }

        foreach ($dati_trimestrali as $p => $d) {
            $dati_trimestrali[$p]['valore_medio_transazione'] = 
                $d['num_transazioni_vendita'] > 0 ? ($d['ricavi'] / $d['num_transazioni_vendita']) : 0;
            $dati_trimestrali[$p]['margine_percentuale'] = 
                $d['ricavi'] > 0 ? ($d['margine'] / $d['ricavi']) * 100 : 0;
        }
        ksort($dati_trimestrali);

        $periodi_trimestrali = array_keys($dati_trimestrali);
        foreach ($periodi_trimestrali as $i => $p) {
            if ($i > 0) {
                $prev = $periodi_trimestrali[$i - 1];
                // Ricavi
                if ($dati_trimestrali[$prev]['ricavi'] > 0) {
                    $dati_trimestrali[$p]['crescita_ricavi'] =
                        (($dati_trimestrali[$p]['ricavi'] - $dati_trimestrali[$prev]['ricavi']) /
                         $dati_trimestrali[$prev]['ricavi']) * 100;
                } else {
                    $dati_trimestrali[$p]['crescita_ricavi'] = $dati_trimestrali[$p]['ricavi'] > 0 ? 100 : 0;
                }
                // Margine
                if (abs($dati_trimestrali[$prev]['margine']) > 0) {
                    $dati_trimestrali[$p]['crescita_margine'] =
                        (($dati_trimestrali[$p]['margine'] - $dati_trimestrali[$prev]['margine']) /
                         abs($dati_trimestrali[$prev]['margine'])) * 100;
                } else {
                    $dati_trimestrali[$p]['crescita_margine'] =
                        $dati_trimestrali[$p]['margine'] > 0 ? 100 : ($dati_trimestrali[$p]['margine'] < 0 ? -100 : 0);
                }
            } else {
                $dati_trimestrali[$p]['crescita_ricavi'] = 0;
                $dati_trimestrali[$p]['crescita_margine'] = 0;
            }
        }

        // Aggregazione annuale (identica)
        $dati_annuali = [];
        foreach ($risultati_mensili as $periodo => $d) {
            $anno = $d['anno'];
            if (!isset($dati_annuali[$anno])) {
                $dati_annuali[$anno] = [
                    'anno' => $anno,
                    'ricavi' => 0,
                    'costi' => 0,
                    'margine' => 0,
                    'num_transazioni_vendita' => 0,
                    'num_transazioni_acquisto' => 0
                ];
            }
            $dati_annuali[$anno]['ricavi'] += $d['ricavi'];
            $dati_annuali[$anno]['costi']  += $d['costi'];
            $dati_annuali[$anno]['margine']+= $d['margine'];
            $dati_annuali[$anno]['num_transazioni_vendita'] += $d['num_transazioni_vendita'];
            $dati_annuali[$anno]['num_transazioni_acquisto'] += $d['num_transazioni_acquisto'];
        }
        foreach ($dati_annuali as $a => $d) {
            $dati_annuali[$a]['valore_medio_transazione'] = 
                $d['num_transazioni_vendita'] > 0 ? ($d['ricavi'] / $d['num_transazioni_vendita']) : 0;
            $dati_annuali[$a]['margine_percentuale'] = 
                $d['ricavi'] > 0 ? ($d['margine'] / $d['ricavi']) * 100 : 0;
        }
        ksort($dati_annuali);

        $anni = array_keys($dati_annuali);
        foreach ($anni as $i => $anno) {
            if ($i > 0) {
                $prev = $anni[$i - 1];
                if ($dati_annuali[$prev]['ricavi'] > 0) {
                    $dati_annuali[$anno]['crescita_ricavi'] =
                        (($dati_annuali[$anno]['ricavi'] - $dati_annuali[$prev]['ricavi']) /
                         $dati_annuali[$prev]['ricavi']) * 100;
                } else {
                    $dati_annuali[$anno]['crescita_ricavi'] = $dati_annuali[$anno]['ricavi'] > 0 ? 100 : 0;
                }
                if (abs($dati_annuali[$prev]['margine']) > 0) {
                    $dati_annuali[$anno]['crescita_margine'] =
                        (($dati_annuali[$anno]['margine'] - $dati_annuali[$prev]['margine']) /
                         abs($dati_annuali[$prev]['margine'])) * 100;
                } else {
                    $dati_annuali[$anno]['crescita_margine'] =
                        $dati_annuali[$anno]['margine'] > 0 ? 100 : ($dati_annuali[$anno]['margine'] < 0 ? -100 : 0);
                }
            } else {
                $dati_annuali[$anno]['crescita_ricavi'] = 0;
                $dati_annuali[$anno]['crescita_margine'] = 0;
            }
        }

        // CALCOLO CAGR (stessa identica logica)
        $indici_crescita = [];
        $primo_periodo_valido = null;
        $primi_ricavi = 0;

        foreach ($periodi_unici as $p) {
            if ($risultati_mensili[$p]['ricavi'] > 0) {
                $primo_periodo_valido = $p;
                $primi_ricavi = $risultati_mensili[$p]['ricavi'];
                break;
            }
        }
        $ultimo_periodo = end($periodi_unici);
        $ultimi_ricavi = $risultati_mensili[$ultimo_periodo]['ricavi'];

        if ($primo_periodo_valido && $primi_ricavi > 0 && $ultimi_ricavi > 0) {
            $indice_primo_valido = array_search($primo_periodo_valido, $periodi_unici);
            $num_periodi_validi = count($periodi_unici) - $indice_primo_valido - 1;
            if ($num_periodi_validi > 0) {
                $indici_crescita['cagr_ricavi'] = (pow(($ultimi_ricavi / $primi_ricavi), (1 / $num_periodi_validi)) - 1) * 100;
                $indici_crescita['cagr_periodo_inizio'] = $primo_periodo_valido;
                $indici_crescita['cagr_periodo_fine']   = $ultimo_periodo;
                $indici_crescita['cagr_mesi_analizzati']= $num_periodi_validi;
            } else {
                $indici_crescita['cagr_ricavi'] = 0;
                $indici_crescita['cagr_nota'] = 'Periodo troppo breve per calcolare CAGR';
            }
        } else {
            $indici_crescita['cagr_ricavi'] = 0;
            $indici_crescita['cagr_nota'] = 'Dati insufficienti per calcolare CAGR';
        }

        if ($primo_periodo_valido) {
            $primo_margine = $risultati_mensili[$primo_periodo_valido]['margine'];
            $ultimo_margine = $risultati_mensili[$ultimo_periodo]['margine'];
            if ($primo_margine > 0 && $ultimo_margine > 0 && isset($num_periodi_validi) && $num_periodi_validi > 0) {
                $indici_crescita['cagr_margine'] = (pow(($ultimo_margine / $primo_margine), (1 / $num_periodi_validi)) - 1) * 100;
            } else {
                $indici_crescita['cagr_margine'] = null;
                $indici_crescita['variazione_margine_assoluta'] = $ultimo_margine - $primo_margine;
                $indici_crescita['variazione_margine_percentuale'] =
                    $primo_margine != 0 ? (($ultimo_margine - $primo_margine) / abs($primo_margine)) * 100 : 0;
            }
        } else {
            $indici_crescita['cagr_margine'] = null;
            $indici_crescita['variazione_margine_assoluta'] = 0;
            $indici_crescita['variazione_margine_percentuale'] = 0;
        }

        // Medie MoM (come prima)
        $somma_crescita_mom = 0; $conteggio_mom = 0;
        $somma_crescita_margine_mom = 0; $conteggio_margine_mom = 0;

        foreach ($risultati_mensili as $d) {
            if (isset($d['crescita_ricavi_mom'])) {
                $somma_crescita_mom += $d['crescita_ricavi_mom'];
                $conteggio_mom++;
            }
            if (isset($d['crescita_margine_mom'])) {
                $somma_crescita_margine_mom += $d['crescita_margine_mom'];
                $conteggio_margine_mom++;
            }
        }

        $indici_crescita['media_crescita_mom'] = $conteggio_mom > 0 ? ($somma_crescita_mom / $conteggio_mom) : 0;
        $indici_crescita['media_crescita_margine_mom'] = $conteggio_margine_mom > 0 ? ($somma_crescita_margine_mom / $conteggio_margine_mom) : 0;

        // Trend ultimo trimestre (come prima)
        $ultimi_tre_mesi = array_slice($periodi_unici, -3);
        $trend_ultimo_trimestre = 0;
        foreach ($ultimi_tre_mesi as $p) {
            if (isset($risultati_mensili[$p]['crescita_ricavi_mom'])) {
                $trend_ultimo_trimestre += $risultati_mensili[$p]['crescita_ricavi_mom'];
            }
        }
        $indici_crescita['trend_ultimo_trimestre'] = count($ultimi_tre_mesi) > 0 ? ($trend_ultimo_trimestre / count($ultimi_tre_mesi)) : 0;

        // Indicatori di redditività (identico)
        if ($primo_periodo_valido) {
            $primo_margine = $risultati_mensili[$primo_periodo_valido]['margine'];
            $indici_crescita['margine_percentuale_iniziale'] = 
                $primi_ricavi > 0 ? ($primo_margine / $primi_ricavi) * 100 : 0;
        } else {
            $indici_crescita['margine_percentuale_iniziale'] = 0;
        }
        $indici_crescita['margine_percentuale_finale'] =
            $ultimi_ricavi > 0 ? ($risultati_mensili[$ultimo_periodo]['margine'] / $ultimi_ricavi) * 100 : 0;
        $indici_crescita['variazione_redditivita'] =
            $indici_crescita['margine_percentuale_finale'] - $indici_crescita['margine_percentuale_iniziale'];

        // Output identico
        return [
            'periodi_analizzati' => count($periodi_unici),
            'data_inizio' => $data_inizio,
            'data_fine' => $data_fine,
            'dati_mensili' => $risultati_mensili,
            'dati_trimestrali' => $dati_trimestrali,
            'dati_annuali' => $dati_annuali,
            'indici_crescita' => $indici_crescita
        ];
    } catch (PDOException $e) {
        error_log("ERRORE Calcolo Indici di Crescita: " . $e->getMessage());
        return [
            'errore' => $e->getMessage(),
            'periodi_analizzati' => 0,
            'dati_mensili' => [],
            'dati_trimestrali' => [],
            'dati_annuali' => []
        ];
    }
}

/**
 * Calcola il Customer Retention Rate (tasso di fidelizzazione clienti) su base mensile
 * Versione migliorata con gestione migliore dell'identificazione clienti
 */
function calcola_customer_retention_mensile($db, $anno, $mese_inizio = 1, $mese_fine = 12) {
    
    // Usa la nuova logica per ottenere tutti i pattern di ricavi
    $patterns_ricavi_vendite = getContiByCategoria($db, 'RICAVI_VENDITE');
    $patterns_ricavi_prestazioni = getContiByCategoria($db, 'RICAVI_PRESTAZIONI');
    
    $tutti_patterns_ricavi = array_merge($patterns_ricavi_vendite, $patterns_ricavi_prestazioni);
    
    if (empty($tutti_patterns_ricavi)) {
        return [
            'mensile' => [],
            'crr_medio' => 0,
            'totale_nuovi_clienti' => 0,
            'totale_clienti_persi' => 0,
            'mesi_analizzati' => 0
        ];
    }
    
    // Genera la WHERE clause per i ricavi
    $where_ricavi = buildWhereClauseConti($tutti_patterns_ricavi);
    
    // Inizializza array risultati
    $risultati = [];
    
    // Per ogni mese nell'intervallo
    for ($mese = $mese_inizio; $mese <= $mese_fine; $mese++) {
        $mese_pad = str_pad($mese, 2, '0', STR_PAD_LEFT);
        $data_inizio_mese = "$anno-$mese_pad-01";
        
        // Calcola l'ultimo giorno del mese
        $ultimo_giorno = date('t', strtotime($data_inizio_mese));
        $data_fine_mese = "$anno-$mese_pad-$ultimo_giorno";
        
        // Calcola il mese precedente
        $data_mese_precedente = date('Y-m-d', strtotime("$data_inizio_mese -1 month"));
        $anno_mese_prec = date('Y', strtotime($data_mese_precedente));
        $mese_prec = date('m', strtotime($data_mese_precedente));
        $data_inizio_mese_prec = "$anno_mese_prec-$mese_prec-01";
        $ultimo_giorno_prec = date('t', strtotime($data_inizio_mese_prec));
        $data_fine_mese_prec = "$anno_mese_prec-$mese_prec-$ultimo_giorno_prec";
        
        try {
            // APPROCCIO DIRETTO: usa i valori di cliente/testo direttamente dalla tabella libro_giornale
            // Migliora l'identificazione del cliente usando le annotazioni
            
            // Clienti del mese corrente
            $query_clienti_corrente = "
                SELECT DISTINCT
                LOWER(SUBSTRING(TRIM(annotazioni), 20, 20)) AS identificativo_cliente
            FROM libro_giornale
            WHERE data_registrazione BETWEEN ? AND ?
              AND $where_ricavi
              AND annotazioni IS NOT NULL
              AND TRIM(annotazioni) != ''
              " . buildExcludeSaldiClause($db, 'entrambi') . "
              ";
            
            // Clienti del mese precedente
            $query_clienti_precedente = "
                SELECT DISTINCT
                LOWER(SUBSTRING(TRIM(annotazioni), 20, 20)) AS identificativo_cliente
            FROM libro_giornale
            WHERE data_registrazione BETWEEN ? AND ?
              AND $where_ricavi
              AND annotazioni IS NOT NULL
              AND TRIM(annotazioni) != ''
              " . buildExcludeSaldiClause($db, 'entrambi') . "
              ";
            
            // Esegue query per clienti del mese corrente con parametri posizionali
            $stmt_corrente = $db->prepare($query_clienti_corrente);
            $stmt_corrente->execute([$data_inizio_mese, $data_fine_mese]);
            $clienti_corrente = [];
            while ($row = $stmt_corrente->fetch(PDO::FETCH_ASSOC)) {
                $clienti_corrente[] = $row['identificativo_cliente'];
            }
            
            // Esegue query per clienti del mese precedente con parametri posizionali
            $stmt_precedente = $db->prepare($query_clienti_precedente);
            $stmt_precedente->execute([$data_inizio_mese_prec, $data_fine_mese_prec]);
            $clienti_precedente = [];
            while ($row = $stmt_precedente->fetch(PDO::FETCH_ASSOC)) {
                $clienti_precedente[] = $row['identificativo_cliente'];
            }
            
            // Debug: stampa i primi 5 clienti di ciascun mese per verifica
            $primi_clienti_corrente = array_slice($clienti_corrente, 0, 5);
            $primi_clienti_precedente = array_slice($clienti_precedente, 0, 5);
            
            // Calcola metriche
            $clienti_inizio = count($clienti_precedente);
            $clienti_fine = count($clienti_corrente);
            
            // Clienti mantenuti (presenti in entrambi i periodi)
            $clienti_mantenuti = array_intersect($clienti_corrente, $clienti_precedente);
            $num_clienti_mantenuti = count($clienti_mantenuti);
            
            // Debug lista clienti mantenuti
            $primi_clienti_mantenuti = array_slice($clienti_mantenuti, 0, 5);
            
            // Nuovi clienti (presenti nel periodo corrente ma non in quello precedente)
            $nuovi_clienti = array_diff($clienti_corrente, $clienti_precedente);
            $num_nuovi_clienti = count($nuovi_clienti);
            
            // Clienti persi (presenti nel periodo precedente ma non in quello corrente)
            $clienti_persi = array_diff($clienti_precedente, $clienti_corrente);
            $num_clienti_persi = count($clienti_persi);
            
            // Calcola Customer Retention Rate
            // CRR = (Clienti inizio - Clienti persi) / Clienti inizio * 100
            // Alternativa: CRR = Clienti mantenuti / Clienti inizio * 100
            $crr = $clienti_inizio > 0 ? ($num_clienti_mantenuti / $clienti_inizio) * 100 : 0;
            
            // Memorizza risultati
            $risultati[$mese] = [
                'mese' => $mese,
                'nome_mese' => date('F', strtotime($data_inizio_mese)),
                'nome_mese_it' => date('F', strtotime($data_inizio_mese)),
                'crr' => round($crr, 2),
                'clienti_inizio' => $clienti_inizio,
                'clienti_fine' => $clienti_fine,
                'clienti_mantenuti' => $num_clienti_mantenuti,
                'nuovi_clienti' => $num_nuovi_clienti,
                'clienti_persi' => $num_clienti_persi,
                'periodo_corrente' => [
                    'inizio' => $data_inizio_mese,
                    'fine' => $data_fine_mese
                ],
                'periodo_precedente' => [
                    'inizio' => $data_inizio_mese_prec,
                    'fine' => $data_fine_mese_prec
                ]
            ];
        } catch (PDOException $e) {
            $risultati[$mese] = [
                'mese' => $mese,
                'nome_mese' => date('F', strtotime("$anno-$mese_pad-01")),
                'crr' => 0,
                'clienti_inizio' => 0,
                'clienti_fine' => 0,
                'clienti_mantenuti' => 0,
                'nuovi_clienti' => 0,
                'clienti_persi' => 0,
                'errore' => $e->getMessage()
            ];
        }
    }
    
    // Calcola medie e totali
    $totale_crr = 0;
    $totale_mesi_validi = 0;
    $totale_nuovi_clienti = 0;
    $totale_clienti_persi = 0;
    
    foreach ($risultati as $mese => $dati) {
        if ($dati['clienti_inizio'] > 0) {
            $totale_crr += $dati['crr'];
            $totale_mesi_validi++;
        }
        $totale_nuovi_clienti += $dati['nuovi_clienti'];
        $totale_clienti_persi += $dati['clienti_persi'];
    }
    
    $crr_medio = $totale_mesi_validi > 0 ? $totale_crr / $totale_mesi_validi : 0;
    
    return [
        'mensile' => $risultati,
        'crr_medio' => round($crr_medio, 2),
        'totale_nuovi_clienti' => $totale_nuovi_clienti,
        'totale_clienti_persi' => $totale_clienti_persi,
        'mesi_analizzati' => count($risultati)
    ];
}

/**
 * Calcola il Customer Retention Rate per quadrimestri basandosi sulla tabella libro_giornale
 * Utilizza i primi 5 caratteri delle annotazioni (puliti) come identificatore cliente
 * 
 * @param PDO $db Connessione al database
 * @param int $anno Anno di riferimento
 * @return array Array con i dati del Customer Retention Rate per quadrimestri
 */
function calcola_customer_retention_giornale($db, $anno) {
    
    // Usa la nuova logica per ottenere tutti i pattern di ricavi
    $patterns_ricavi_vendite = getContiByCategoria($db, 'RICAVI_VENDITE');
    $patterns_ricavi_prestazioni = getContiByCategoria($db, 'RICAVI_PRESTAZIONI');
    $tutti_patterns_ricavi = array_merge($patterns_ricavi_vendite, $patterns_ricavi_prestazioni);
    
    if (empty($tutti_patterns_ricavi)) {
        return [
            'anno' => $anno,
            'quadrimestri' => [],
            'crr_medio' => 0,
            'crr_anno' => 0,
            'totale_clienti' => 0,
            'totale_clienti_anno_precedente' => 0,
            'clienti_attivi' => 0, // ← Aggiungo clienti_attivi per compatibilità
            'clienti_ricorrenti' => 0,
            'totale_nuovi_clienti' => 0,
            'totale_clienti_persi' => 0,
            'clienti_correnti_codici' => [],
            'clienti_nuovi_codici' => [],
            'clienti_persi_codici' => []
        ];
    }
    
    // Genera la WHERE clause per i ricavi
    $where_ricavi = buildWhereClauseConti($tutti_patterns_ricavi);
    
    // Definizione dei TRIMESTRI (corretti!)
    $quadrimestri = [
        1 => [
            'inizio' => "$anno-01-01",
            'fine' => "$anno-03-31",        // ← MARZO non APRILE!
            'nome' => "Q1 $anno"
        ],
        2 => [
            'inizio' => "$anno-04-01",      // ← APRILE non MAGGIO!
            'fine' => "$anno-06-30",
            'nome' => "Q2 $anno"
        ],
        3 => [
            'inizio' => "$anno-07-01",
            'fine' => "$anno-09-30",
            'nome' => "Q3 $anno"
        ],
        4 => [
            'inizio' => "$anno-10-01",
            'fine' => "$anno-12-31",
            'nome' => "Q4 $anno"
        ]
    ];
    
    // Definizione dei trimestri dell'anno precedente
    $anno_prec = $anno - 1;
    $quadrimestri_prec = [
        1 => [
            'inizio' => "$anno_prec-01-01",
            'fine' => "$anno_prec-03-31",
            'nome' => "Q1 $anno_prec"
        ],
        2 => [
            'inizio' => "$anno_prec-04-01",
            'fine' => "$anno_prec-06-30",
            'nome' => "Q2 $anno_prec"
        ],
        3 => [
            'inizio' => "$anno_prec-07-01",
            'fine' => "$anno_prec-09-30",
            'nome' => "Q3 $anno_prec"
        ],
        4 => [
            'inizio' => "$anno_prec-10-01",
            'fine' => "$anno_prec-12-31",
            'nome' => "Q4 $anno_prec"
        ]
    ];
        
    /**
     * FUNZIONE NUOVA (migliorata)
     */
    function genera_codice_cliente($annotazione) {
        $pos_virgola = strpos($annotazione, ',');
        
        if ($pos_virgola !== false) {
            $parte_rilevante = substr($annotazione, 0, $pos_virgola);
        } else {
            $parte_rilevante = substr($annotazione, 0, 50);
        }
        
        $codice = strtolower(trim($parte_rilevante));
        $codice = preg_replace('/[^a-z0-9\s]/', '', $codice);
        $codice = preg_replace('/\s+/', ' ', $codice);
        
        $parole = explode(' ', $codice);
        $parole_significative = [];
        
        foreach ($parole as $parola) {
            if (strlen($parola) >= 2 && !in_array($parola, ['di', 'del', 'dei', 'da', 'per', 'con', 'srl', 'spa', 'snc', 'nr'])) {
                $parole_significative[] = $parola;
                if (count($parole_significative) >= 3) break;
            }
        }
        
        if (empty($parole_significative)) {
            $codice_finale = preg_replace('/\s/', '', $codice);
            return substr($codice_finale, 0, 15);
        }
        
        $codice_finale = implode('', $parole_significative);
        return substr($codice_finale, 0, 20);
    }
    
    // Query base per estrarre le transazioni dei clienti con parametri posizionali
    $query_base = "SELECT 
        data_registrazione,
        annotazioni,
        SUBSTRING(annotazioni, 1, 20) AS nome_cliente,
        avere,
        conto
    FROM libro_giornale
    WHERE data_registrazione BETWEEN ? AND ?
      AND $where_ricavi
      AND avere > 0
      AND annotazioni IS NOT NULL AND TRIM(annotazioni) != ''
      " . buildExcludeSaldiClause($db, 'entrambi') . "
      ";
    
    try {
        $risultati = [];
        $totale_crr = 0;
        $num_trimestri_validi = 0;
        
        // Variabili per i totali annuali
        $totale_nuovi_clienti = 0;
        $totale_clienti_persi = 0;
        
        // Per ogni quadrimestre dell'anno corrente
        foreach ($quadrimestri as $num => $quad) {
            // Dati quadrimestre corrente con parametri posizionali
            $stmt_corrente = $db->prepare($query_base);
            $stmt_corrente->execute([$quad['inizio'], $quad['fine']]);
            
            $clienti_corrente = [];
            $fatturato_corrente = 0;
            
            while ($row = $stmt_corrente->fetch(PDO::FETCH_ASSOC)) {
                $codice_cliente = genera_codice_cliente($row['annotazioni']);
                $clienti_corrente[$codice_cliente] = true;
                $fatturato_corrente += floatval($row['avere']);
            }
            
            // Dati quadrimestre precedente con parametri posizionali
            $stmt_precedente = $db->prepare($query_base);
            $stmt_precedente->execute([$quadrimestri_prec[$num]['inizio'], $quadrimestri_prec[$num]['fine']]);
            
            $clienti_precedente = [];
            $fatturato_precedente = 0;
            
            while ($row = $stmt_precedente->fetch(PDO::FETCH_ASSOC)) {
                $codice_cliente = genera_codice_cliente($row['annotazioni']);
                $clienti_precedente[$codice_cliente] = true;
                $fatturato_precedente += floatval($row['avere']);
            }
            
            // Calcola i clienti unici
            $clienti_correnti = array_keys($clienti_corrente);
            $clienti_precedenti = array_keys($clienti_precedente);
            
            
            // Calcola clienti ricorrenti, persi e nuovi
            $clienti_ricorrenti = array_intersect($clienti_correnti, $clienti_precedenti);
            $clienti_persi = array_diff($clienti_precedenti, $clienti_correnti);
            $clienti_nuovi = array_diff($clienti_correnti, $clienti_precedenti);
            
            // Calcola metriche
            $num_clienti_correnti = count($clienti_correnti);
            $num_clienti_precedenti = count($clienti_precedenti);
            $num_clienti_ricorrenti = count($clienti_ricorrenti);
            $num_clienti_persi = count($clienti_persi);
            $num_clienti_nuovi = count($clienti_nuovi);
            
            // Aggiorna i totali annuali
            $totale_nuovi_clienti += $num_clienti_nuovi;
            $totale_clienti_persi += $num_clienti_persi;
            
            // Calcola Customer Retention Rate
            $crr = $num_clienti_precedenti > 0 ? 
                round(($num_clienti_ricorrenti / $num_clienti_precedenti) * 100, 2) : 0;
            
            // Solo per quadrimestri con clienti nell'anno precedente
            if ($num_clienti_precedenti > 0) {
                $totale_crr += $crr;
                $num_trimestri_validi++;
            }
            
            
            // Memorizza i risultati
            $risultati[$num] = [
                'quadrimestre' => $num,
                'nome' => $quad['nome'],
                'periodo' => [
                    'inizio' => $quad['inizio'],
                    'fine' => $quad['fine']
                ],
                'periodo_precedente' => [
                    'inizio' => $quadrimestri_prec[$num]['inizio'],
                    'fine' => $quadrimestri_prec[$num]['fine']
                ],
                'num_clienti_correnti' => $num_clienti_correnti,
                'num_clienti_precedenti' => $num_clienti_precedenti,
                'num_clienti_ricorrenti' => $num_clienti_ricorrenti,
                'num_clienti_persi' => $num_clienti_persi,
                'num_clienti_nuovi' => $num_clienti_nuovi,
                'crr' => $crr,
                'fatturato_corrente' => $fatturato_corrente,
                'fatturato_precedente' => $fatturato_precedente
            ];
        }
        
        // Calcola CRR medio
        $crr_medio = $num_trimestri_validi > 0 ? 
            round($totale_crr / $num_trimestri_validi, 2) : 0;
        
        // Calcola totali e medie per l'anno intero con parametri posizionali
        $stmt_anno_corrente = $db->prepare($query_base);
        $stmt_anno_corrente->execute([$quadrimestri[1]['inizio'], $quadrimestri[4]['fine']]);
        
        $clienti_anno_corrente = [];
        while ($row = $stmt_anno_corrente->fetch(PDO::FETCH_ASSOC)) {
            $codice_cliente = genera_codice_cliente($row['annotazioni']);
            $clienti_anno_corrente[$codice_cliente] = true;
        }
        
        $stmt_anno_precedente = $db->prepare($query_base);
        $stmt_anno_precedente->execute([$quadrimestri_prec[1]['inizio'], $quadrimestri_prec[4]['fine']]);
        
        $clienti_anno_precedente = [];
        while ($row = $stmt_anno_precedente->fetch(PDO::FETCH_ASSOC)) {
            $codice_cliente = genera_codice_cliente($row['annotazioni']);
            $clienti_anno_precedente[$codice_cliente] = true;
        }
        
        $clienti_anno_corrente_keys = array_keys($clienti_anno_corrente);
        $clienti_anno_precedente_keys = array_keys($clienti_anno_precedente);
        
        $clienti_ricorrenti_anno = array_intersect($clienti_anno_corrente_keys, $clienti_anno_precedente_keys);
        $clienti_nuovi_anno = array_diff($clienti_anno_corrente_keys, $clienti_anno_precedente_keys);
        $clienti_persi_anno = array_diff($clienti_anno_precedente_keys, $clienti_anno_corrente_keys);
        
        $num_clienti_ricorrenti_anno = count($clienti_ricorrenti_anno);
        $num_clienti_precedenti_anno = count($clienti_anno_precedente_keys);
        $num_nuovi_clienti_anno = count($clienti_nuovi_anno);
        $num_persi_clienti_anno = count($clienti_persi_anno);
        
        // In caso di discrepanza, usa i totali calcolati a livello annuale
        // ma solo se sono maggiori (per evitare di perdere clienti contati nei trimestri)
        if ($num_nuovi_clienti_anno > $totale_nuovi_clienti) {
            $totale_nuovi_clienti = $num_nuovi_clienti_anno;
        }
        
        if ($num_persi_clienti_anno > $totale_clienti_persi) {
            $totale_clienti_persi = $num_persi_clienti_anno;
        }
        
        $crr_anno = $num_clienti_precedenti_anno > 0 ? 
            round(($num_clienti_ricorrenti_anno / $num_clienti_precedenti_anno) * 100, 2) : 0;
        
        // Usa CRR annuale se è più significativo (più clienti coinvolti)
        if ($num_clienti_precedenti_anno > array_sum(array_column($risultati, 'num_clienti_precedenti'))) {
            $crr_medio = $crr_anno;
        }
        
        // Risultati finali
        return [
            'anno' => $anno,
            'quadrimestri' => $risultati,                    // ← MANTIENI IL NOME!
            'crr_medio' => $crr_medio,
            'crr_anno' => $crr_anno,
            'totale_clienti' => count($clienti_anno_corrente_keys),
            'totale_clienti_anno_precedente' => count($clienti_anno_precedente_keys),
            'clienti_attivi' => $num_clienti_ricorrenti_anno,     // ← Aggiungo per compatibilità
            'clienti_ricorrenti' => $num_clienti_ricorrenti_anno,
            'totale_nuovi_clienti' => $totale_nuovi_clienti,
            'totale_clienti_persi' => $totale_clienti_persi,
            'clienti_correnti_codici' => $clienti_anno_corrente_keys,
            'clienti_nuovi_codici' => array_values($clienti_nuovi_anno),
            'clienti_persi_codici' => array_values($clienti_persi_anno)
        ];
        
    } catch (PDOException $e) {
        error_log("ERRORE Calcolo Customer Retention Rate: " . $e->getMessage());
        return [
            'errore' => $e->getMessage(),
            'quadrimestri' => [],
            'crr_medio' => 0,
            'crr_anno' => 0,
            'totale_clienti' => 0,
            'clienti_attivi' => 0,
            'totale_nuovi_clienti' => 0,
            'totale_clienti_persi' => 0
        ];
    }
}

/**

// ============ FUNZIONI HELPER PER RIMANENZE ============

/**
 * Calcola le rimanenze iniziali per il periodo
 */
function get_rimanenze_iniziali($db, $data_inizio) {
    $patterns = getContiByCategoria($db, 'RIMANENZE_INIZIALI');
    
    if (empty($patterns)) {
        return 0;
    }
    
    $where_clause = buildWhereClauseConti($patterns);
    
    $query = "
        SELECT COALESCE(SUM(CASE WHEN $where_clause THEN dare ELSE 0 END), 0) as rimanenze
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'apertura') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_inizio]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return abs($result['rimanenze'] ?? 0);
    } catch (PDOException $e) {
        error_log("Errore rimanenze iniziali: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola le rimanenze finali per il periodo
 */
function get_rimanenze_finali($db, $data_fine) {
    $patterns = getContiByCategoria($db, 'RIMANENZE_FINALI');
    
    if (empty($patterns)) {
        return 0;
    }
    
    $where_clause = buildWhereClauseConti($patterns);
    
    $query = "
        SELECT COALESCE(SUM(CASE WHEN $where_clause THEN avere ELSE 0 END), 0) as rimanenze
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'chiusura') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_fine, $data_fine]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return abs($result['rimanenze'] ?? 0);
    } catch (PDOException $e) {
        error_log("Errore rimanenze finali: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola il Costo del Venduto separato:
 */
function calcola_costo_venduto_giornale($db, $data_inizio, $data_fine) {
    $rimanenze_iniziali = get_rimanenze_iniziali($db, $data_inizio);
    $acquisti = calcola_costi_diretti_giornale($db, $data_inizio, $data_fine);
    $rimanenze_finali = get_rimanenze_finali($db, $data_fine);
    
    return $rimanenze_iniziali + $acquisti - $rimanenze_finali;
}

/**
 * Restituisce il territorio dell'azienda (Città + Nazione)
 * recuperandolo dalla tabella configurazioni_sistema
 * 
 * @param PDO $db Connessione al database
 * @return string Territorio dell'azienda (es. "Milano, Italia")
 */
function get_territorio_azienda($db) {

    $stmt = $db->prepare("SELECT chiave, valore FROM configurazioni_sistema WHERE chiave IN ('CITTA_AZIENDA', 'NAZIONE_AZIENDA')");
    $stmt->execute();
    $config_azienda = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    return trim(
        ($config_azienda['CITTA_AZIENDA'] ?? '') . 
        (isset($config_azienda['CITTA_AZIENDA']) ? ', ' : '') . 
        ($config_azienda['NAZIONE_AZIENDA'] ?? 'Italia')
    );
}

/**
 * Calcola il capitale sociale effettivo:
 * - se mappato nel giornale (saldo credito > 0), usa quello
 * - altrimenti fallback alla costante CAPITALE_SOCIALE
 */
function get_capitale_sociale_effettivo(PDO $db, string $data_fine): float {
    $patterns_capitale = getContiByCategoria($db, 'CAPITALE_SOCIALE');

    if (!empty($patterns_capitale)) {
        // buildWhereClauseConti deve restituire una condizione SQL sui conti (es. "(conto LIKE 'XYZ%' OR ...)")
        $where_capitale = buildWhereClauseConti($patterns_capitale);

        $sql = "
            SELECT COALESCE(SUM(COALESCE(avere,0) - COALESCE(dare,0)), 0) AS capitale_giornale
            FROM libro_giornale
            WHERE ($where_capitale)
              AND data_registrazione <= ?
            " . buildExcludeSaldiClause($db, 'chiusura') . "
        ";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute([$data_fine]); // NB: se buildWhereClauseConti usa placeholder, passa qui anche i suoi parametri
            $capitale_giornale = (float)($stmt->fetchColumn() ?: 0);

            // Se trovi un saldo creditorio positivo, lo usi; altrimenti fallback
            if ($capitale_giornale > 0.0) {
                return $capitale_giornale;
            }
        } catch (PDOException $e) {
            error_log("Errore calcolo capitale sociale dal giornale: " . $e->getMessage());
        }
    }

    return defined('CAPITALE_SOCIALE') ? (float)CAPITALE_SOCIALE : 0.0;
}


/**
 * Calcola i ricavi da plusvalenze per cessioni utilizzando i dati del libro giornale
 * Utilizza la categoria PLUSVALENZE_CESSIONI
 * 
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float Totale dei ricavi da plusvalenze cessioni nel periodo
 */
function ricavi_plusvalenze_cessioni_giornale($db, $data_inizio, $data_fine) {
    
    // Usa la logica standard per ottenere i ricavi plusvalenze cessioni
    $patterns_plusvalenze_cessioni = getContiByCategoria($db, 'PLUSVALENZE_CESSIONI');
    
    // Se non ci sono pattern configurati, ritorna 0
    if (empty($patterns_plusvalenze_cessioni)) {
        return 0;
    }
    
    // Genera dinamicamente la WHERE clause basata sui pattern configurati
    $where_plusvalenze = buildWhereClauseConti($patterns_plusvalenze_cessioni);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_plusvalenze THEN avere ELSE 0 END), 0) as totale_avere,
            COALESCE(SUM(CASE WHEN $where_plusvalenze THEN dare ELSE 0 END), 0) as totale_dare
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // I ricavi sono solitamente registrati in AVERE, ma possono esserci stornati in DARE
        // Per i conti di ricavo, il saldo è: AVERE - DARE
        $totale_ricavi = abs($result['totale_avere'] - $result['totale_dare']);
        
        return $totale_ricavi;
    } catch (PDOException $e) {
        // Log dell'errore
        error_log("Errore nel calcolo ricavi plusvalenze cessioni da giornale: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola i ricavi da altre plusvalenze utilizzando i dati del libro giornale
 * Utilizza la categoria ALTRE_PLUSVALENZE
 * 
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float Totale dei ricavi da altre plusvalenze nel periodo
 */
function ricavi_altre_plusvalenze_giornale($db, $data_inizio, $data_fine) {
    
    // Usa la logica standard per ottenere i ricavi altre plusvalenze
    $patterns_altre_plusvalenze = getContiByCategoria($db, 'ALTRE_PLUSVALENZE');
    
    // Se non ci sono pattern configurati, ritorna 0
    if (empty($patterns_altre_plusvalenze)) {
        return 0;
    }
    
    // Genera dinamicamente la WHERE clause basata sui pattern configurati
    $where_plusvalenze = buildWhereClauseConti($patterns_altre_plusvalenze);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_plusvalenze THEN avere ELSE 0 END), 0) as totale_avere,
            COALESCE(SUM(CASE WHEN $where_plusvalenze THEN dare ELSE 0 END), 0) as totale_dare
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // I ricavi sono solitamente registrati in AVERE, ma possono esserci stornati in DARE
        // Per i conti di ricavo, il saldo è: AVERE - DARE
        $totale_ricavi = abs($result['totale_avere'] - $result['totale_dare']);
        
        return $totale_ricavi;
    } catch (PDOException $e) {
        // Log dell'errore
        error_log("Errore nel calcolo ricavi altre plusvalenze da giornale: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola i ricavi da sopravvenienze attive utilizzando i dati del libro giornale
 * Utilizza la categoria SOPRAVVENIENZE_ATTIVE
 * 
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float Totale dei ricavi da sopravvenienze attive nel periodo
 */
function ricavi_sopravvenienze_attive_giornale($db, $data_inizio, $data_fine) {
    
    // Usa la logica standard per ottenere i ricavi sopravvenienze attive
    $patterns_sopravvenienze = getContiByCategoria($db, 'SOPRAVVENIENZE_ATTIVE');
    
    // Se non ci sono pattern configurati, ritorna 0
    if (empty($patterns_sopravvenienze)) {
        return 0;
    }
    
    // Genera dinamicamente la WHERE clause basata sui pattern configurati
    $where_sopravvenienze = buildWhereClauseConti($patterns_sopravvenienze);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_sopravvenienze THEN avere ELSE 0 END), 0) as totale_avere,
            COALESCE(SUM(CASE WHEN $where_sopravvenienze THEN dare ELSE 0 END), 0) as totale_dare
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // I ricavi sono solitamente registrati in AVERE, ma possono esserci stornati in DARE
        // Per i conti di ricavo, il saldo è: AVERE - DARE
        $totale_ricavi = abs($result['totale_avere'] - $result['totale_dare']);
        
        return $totale_ricavi;
    } catch (PDOException $e) {
        // Log dell'errore
        error_log("Errore nel calcolo ricavi sopravvenienze attive da giornale: " . $e->getMessage());
        return 0;
    }
}

/**
 * Ottiene il dettaglio completo di tutti i centri di ricavo per un periodo
 * Funzione helper che aggrega tutte le funzioni di calcolo ricavi
 * 
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return array Array associativo con tutti i centri di ricavo e verifica somma
 */
function get_dettaglio_centri_ricavo_giornale($db, $data_inizio, $data_fine) {
    
    // Calcola tutti i centri di ricavo operativi
    $vendite = ricavi_prodotti_giornale($db, $data_inizio, $data_fine);
    $corrispettivi = ricavi_corrispettivi_giornale($db, $data_inizio, $data_fine);
    $prestazioni = ricavi_prestazioni_giornale($db, $data_inizio, $data_fine);
    
    // Calcola i centri di ricavo straordinari
    $plusvalenze_cessioni = ricavi_plusvalenze_cessioni_giornale($db, $data_inizio, $data_fine);
    $altre_plusvalenze = ricavi_altre_plusvalenze_giornale($db, $data_inizio, $data_fine);
    $sopravvenienze_attive = ricavi_sopravvenienze_attive_giornale($db, $data_inizio, $data_fine);
    
    // Calcola la somma dei centri
    $somma_centri = $vendite + $corrispettivi + $prestazioni + 
                    $plusvalenze_cessioni + $altre_plusvalenze + $sopravvenienze_attive;
    
    // Calcola il totale ricavi dalla funzione aggregata esistente
    $ricavi_totali = calcola_ricavi_totali_giornale($db, $data_inizio, $data_fine);
    
    // Verifica coerenza (tolleranza di 1 euro per arrotondamenti)
    $discrepanza = abs($somma_centri - $ricavi_totali);
    if ($discrepanza > 1) {
        error_log("ATTENZIONE: Discrepanza centri ricavo! Somma centri: €" . 
                  number_format($somma_centri, 2) . 
                  " vs Totale: €" . number_format($ricavi_totali, 2) . 
                  " (Diff: €" . number_format($discrepanza, 2) . ")");
    }
    
    // Calcola percentuali (evita divisione per zero)
    $calcola_percentuale = function($valore) use ($ricavi_totali) {
        return $ricavi_totali > 0 ? ($valore / $ricavi_totali) * 100 : 0;
    };
    
    // Restituisci array completo con tutte le informazioni
    return [
        'centri' => [
            'vendite' => [
                'nome' => 'Ricavi da Vendite',
                'valore' => $vendite,
                'percentuale' => $calcola_percentuale($vendite),
                'icona' => 'fas fa-cash-register',
                'colore' => '#28a745'
            ],
            'corrispettivi' => [
                'nome' => 'Corrispettivi',
                'valore' => $corrispettivi,
                'percentuale' => $calcola_percentuale($corrispettivi),
                'icona' => 'fas fa-coins',
                'colore' => '#20c997'
            ],
            'prestazioni' => [
                'nome' => 'Prestazioni di Servizi',
                'valore' => $prestazioni,
                'percentuale' => $calcola_percentuale($prestazioni),
                'icona' => 'fas fa-handshake',
                'colore' => '#17a2b8'
            ],
            'plusvalenze_cessioni' => [
                'nome' => 'Plusvalenze da Cessioni',
                'valore' => $plusvalenze_cessioni,
                'percentuale' => $calcola_percentuale($plusvalenze_cessioni),
                'icona' => 'fas fa-chart-line',
                'colore' => '#6f42c1'
            ],
            'altre_plusvalenze' => [
                'nome' => 'Altre Plusvalenze',
                'valore' => $altre_plusvalenze,
                'percentuale' => $calcola_percentuale($altre_plusvalenze),
                'icona' => 'fas fa-arrow-trend-up',
                'colore' => '#e83e8c'
            ],
            'sopravvenienze_attive' => [
                'nome' => 'Sopravvenienze Attive',
                'valore' => $sopravvenienze_attive,
                'percentuale' => $calcola_percentuale($sopravvenienze_attive),
                'icona' => 'fas fa-gift',
                'colore' => '#fd7e14'
            ]
        ],
        'totali' => [
            'somma_centri' => $somma_centri,
            'ricavi_totali' => $ricavi_totali,
            'discrepanza' => $discrepanza,
            'coerenza_ok' => $discrepanza <= 1
        ],
        'periodo' => [
            'data_inizio' => $data_inizio,
            'data_fine' => $data_fine
        ]
    ];
}

/**
 * Filtra i centri di ricavo eliminando quelli a zero
 * Helper per visualizzazioni (grafici e tabelle)
 * 
 * @param array $centri Array dei centri da get_dettaglio_centri_ricavo_giornale()
 * @return array Array con solo i centri che hanno valore > 0
 */
function filtra_centri_ricavo_attivi($centri) {
    $centri_attivi = [];
    
    foreach ($centri as $key => $centro) {
        if ($centro['valore'] > 0) {
            $centri_attivi[$key] = $centro;
        }
    }
    
    return $centri_attivi;
}

// =====================================================
// SEZIONE: ANALISI CENTRI DI COSTO GRANULARE
// Aggiunto per Dashboard Economic - NEITCUS 3.0
// Pattern identico alle funzioni ricavi esistenti
// NON modifica funzioni esistenti - Solo aggiunta
// =====================================================

// =====================================================
// FIX CRITICO: Rimozione abs() dalle funzioni centri di costo
// PROBLEMA: Le funzioni usavano abs(dare - avere) mentre calcola_costi_totali_giornale usa (dare - avere)
// EFFETTO: Gli storni (avere > dare) venivano contati positivamente invece di ridurre il totale
// SOLUZIONE: Usare (dare - avere) senza abs() in TUTTE le funzioni
// =====================================================

/**
 * 1. FIX costi_personale_giornale()
 */
function costi_personale_giornale($db, $data_inizio, $data_fine) {
    
    $patterns_costi_personale = getContiByCategoria($db, 'COSTI_PERSONALE');
    $patterns_oneri_sociali = getContiByCategoria($db, 'ONERI_SOCIALI');
    
    $tutti_patterns_personale = array_merge(
        $patterns_costi_personale,
        $patterns_oneri_sociali
    );
    
    if (empty($tutti_patterns_personale)) {
        return 0;
    }
    
    $where_personale = buildWhereClauseConti($tutti_patterns_personale);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_personale THEN dare - avere ELSE 0 END), 0) as totale_costi
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // RIMOSSO abs() - i costi sono già in dare, se negativo è uno storno
        $totale_costi = $result['totale_costi'];
        
        return $totale_costi;
    } catch (PDOException $e) {
        error_log("Errore nel calcolo costi personale da giornale: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcola i costi di produzione (centro costo)
 * Include SOLO i costi diretti (da natura_costo)
 * 
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return float Totale costi produzione nel periodo
 */
function costi_produzione_giornale($db, $data_inizio, $data_fine) {
    
    // Usa il nuovo sistema natura_costo
    // Produzione = SOLO costi diretti
    $patterns_produzione = getContiByNaturaCosto($db, 'diretto');
    
    if (empty($patterns_produzione)) {
        return 0;
    }
    
    $where_produzione = buildWhereClauseConti($patterns_produzione);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_produzione THEN dare - avere ELSE 0 END), 0) as totale_costi
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $totale_costi = $result['totale_costi'];
        
        return $totale_costi;
    } catch (PDOException $e) {
        error_log("Errore nel calcolo costi produzione da giornale: " . $e->getMessage());
        return 0;
    }
}

/**
 * 3. FIX costi_it_software_giornale()
 */
function costi_it_software_giornale($db, $data_inizio, $data_fine) {
    
    $patterns_it_software = getContiByCategoria($db, 'COSTI_IT_SOFTWARE');
    
    if (empty($patterns_it_software)) {
        return 0;
    }
    
    $where_it_software = buildWhereClauseConti($patterns_it_software);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_it_software THEN dare - avere ELSE 0 END), 0) as totale_costi
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // RIMOSSO abs()
        $totale_costi = $result['totale_costi'];
        
        return $totale_costi;
    } catch (PDOException $e) {
        error_log("Errore nel calcolo costi IT/Software da giornale: " . $e->getMessage());
        return 0;
    }
}

/**
 * 4. FIX costi_marketing_giornale()
 */
function costi_marketing_giornale($db, $data_inizio, $data_fine) {
    
    $patterns_marketing = getContiByCategoria($db, 'COSTI_MARKETING');
    
    if (empty($patterns_marketing)) {
        return 0;
    }
    
    $where_marketing = buildWhereClauseConti($patterns_marketing);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_marketing THEN dare - avere ELSE 0 END), 0) as totale_costi
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // RIMOSSO abs()
        $totale_costi = $result['totale_costi'];
        
        return $totale_costi;
    } catch (PDOException $e) {
        error_log("Errore nel calcolo costi marketing da giornale: " . $e->getMessage());
        return 0;
    }
}

/**
 * 5. FIX costi_amministrativi_giornale()
 */
function costi_amministrativi_giornale($db, $data_inizio, $data_fine) {
    
    $patterns_costi_indiretti = getContiByCategoria($db, 'COSTI_INDIRETTI');
    $patterns_costi_servizi = getContiByCategoria($db, 'COSTI_SERVIZI');
    
    $tutti_patterns_amministrativi = array_merge(
        $patterns_costi_indiretti,
        $patterns_costi_servizi
    );
    
    if (empty($tutti_patterns_amministrativi)) {
        return 0;
    }
    
    $where_amministrativi = buildWhereClauseConti($tutti_patterns_amministrativi);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_amministrativi THEN dare - avere ELSE 0 END), 0) as totale_costi
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // RIMOSSO abs()
        $totale_costi = $result['totale_costi'];
        
        return $totale_costi;
    } catch (PDOException $e) {
        error_log("Errore nel calcolo costi amministrativi da giornale: " . $e->getMessage());
        return 0;
    }
}

/**
 * 6. FIX costi_affitti_utenze_giornale()
 */
function costi_affitti_utenze_giornale($db, $data_inizio, $data_fine) {
    
    $patterns_affitti_utenze = getContiByCategoria($db, 'COSTI_AFFITTI_UTENZE');
    
    if (empty($patterns_affitti_utenze)) {
        return 0;
    }
    
    $where_affitti_utenze = buildWhereClauseConti($patterns_affitti_utenze);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_affitti_utenze THEN dare - avere ELSE 0 END), 0) as totale_costi
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // RIMOSSO abs()
        $totale_costi = $result['totale_costi'];
        
        return $totale_costi;
    } catch (PDOException $e) {
        error_log("Errore nel calcolo costi affitti/utenze da giornale: " . $e->getMessage());
        return 0;
    }
}

/**
 * 7. FIX costi_oneri_finanziari_giornale()
 */
function costi_oneri_finanziari_giornale($db, $data_inizio, $data_fine) {
    
    $patterns_oneri_finanziari = getContiByCategoria($db, 'ONERI_FINANZIARI');
    
    if (empty($patterns_oneri_finanziari)) {
        return 0;
    }
    
    $where_oneri_finanziari = buildWhereClauseConti($patterns_oneri_finanziari);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_oneri_finanziari THEN dare - avere ELSE 0 END), 0) as totale_costi
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // RIMOSSO abs()
        $totale_costi = $result['totale_costi'];
        
        return $totale_costi;
    } catch (PDOException $e) {
        error_log("Errore nel calcolo oneri finanziari da giornale: " . $e->getMessage());
        return 0;
    }
}

/**
 * 8. FIX costi_oneri_finanziari_giornale()
 */
function costi_imposte_tasse_giornale($db, $data_inizio, $data_fine) {
    
    $patterns_imposte = getContiByCategoria($db, 'IMPOSTE_TASSE');
    
    if (empty($patterns_imposte)) {
        return 0;
    }
    
    $where_imposte_tasse = buildWhereClauseConti($patterns_imposte);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_imposte_tasse THEN dare - avere ELSE 0 END), 0) as totale_costi
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // RIMOSSO abs()
        $totale_costi = $result['totale_costi'];
        
        return $totale_costi;
    } catch (PDOException $e) {
        error_log("Errore nel calcolo imposte e tasse: " . $e->getMessage());
        return 0;
    }
}


/**
 * 9. FIX costi_altri_giornale()
 */
function costi_altri_giornale($db, $data_inizio, $data_fine) {
    
    $patterns_ammortamenti = getContiByCategoria($db, 'AMMORTAMENTI');
    $patterns_svalutazioni = getContiByCategoria($db, 'SVALUTAZIONI');
    $patterns_minusvalenze = getContiByCategoria($db, 'MINUSVALENZE_CESSIONI');
    $patterns_altre_minusvalenze = getContiByCategoria($db, 'ALTRE_MINUSVALENZE');
    $patterns_oneri_vari = getContiByCategoria($db, 'ONERI_VARI');
    $patterns_sopravvenienze_passive = getContiByCategoria($db, 'SOPRAVVENIENZE_PASSIVE');
    
    $tutti_patterns_altri = array_merge(
        $patterns_ammortamenti,
        $patterns_svalutazioni,
        $patterns_minusvalenze,
        $patterns_altre_minusvalenze,
        $patterns_oneri_vari,
        $patterns_sopravvenienze_passive
    );
    
    if (empty($tutti_patterns_altri)) {
        return 0;
    }
    
    $where_altri = buildWhereClauseConti($tutti_patterns_altri);
    
    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN $where_altri THEN dare - avere ELSE 0 END), 0) as totale_costi
        FROM libro_giornale
        WHERE data_registrazione BETWEEN ? AND ?
        " . buildExcludeSaldiClause($db, 'entrambi') . "
    ";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute([$data_inizio, $data_fine]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // RIMOSSO abs()
        $totale_costi = $result['totale_costi'];
        
        return $totale_costi;
    } catch (PDOException $e) {
        error_log("Errore nel calcolo altri costi da giornale: " . $e->getMessage());
        return 0;
    }
}



/**
 * 9. MASTER FUNCTION - Ottiene il dettaglio completo di tutti i centri di costo per un periodo
 * Funzione aggregata che chiama tutte le 8 funzioni atomiche e verifica la coerenza
 * Pattern identico a get_dettaglio_centri_ricavo_giornale()
 * 
 * @param PDO $db Connessione al database
 * @param string $data_inizio Data inizio periodo (formato Y-m-d)
 * @param string $data_fine Data fine periodo (formato Y-m-d)
 * @return array Array associativo con tutti i centri di costo e verifica somma
 */
function get_dettaglio_centri_costo_giornale($db, $data_inizio, $data_fine) {
    
    // Calcola tutti gli 8 centri di costo
    $personale = costi_personale_giornale($db, $data_inizio, $data_fine);
    $produzione = costi_produzione_giornale($db, $data_inizio, $data_fine);
    $it_software = costi_it_software_giornale($db, $data_inizio, $data_fine);
    $marketing = costi_marketing_giornale($db, $data_inizio, $data_fine);
    $amministrativi = costi_amministrativi_giornale($db, $data_inizio, $data_fine);
    $affitti_utenze = costi_affitti_utenze_giornale($db, $data_inizio, $data_fine);
    $oneri_finanziari = costi_oneri_finanziari_giornale($db, $data_inizio, $data_fine);
    $imposte_tasse = costi_imposte_tasse_giornale($db, $data_inizio, $data_fine);
    $altri = costi_altri_giornale($db, $data_inizio, $data_fine);
    
    // Calcola la somma dei centri
    $somma_centri = $personale + $produzione + $it_software + $marketing + 
                    $amministrativi + $affitti_utenze + $oneri_finanziari + $imposte_tasse + $altri;
    
    // Calcola il totale costi dalla funzione aggregata esistente
    $costi_totali = calcola_costi_totali_giornale($db, $data_inizio, $data_fine);
    
    // Verifica coerenza (tolleranza di 1 euro per arrotondamenti)
    $discrepanza = abs($somma_centri - $costi_totali);
    if ($discrepanza > 1) {
        error_log("ATTENZIONE: Discrepanza centri costo! Somma centri: €" . 
                  number_format($somma_centri, 2) . 
                  " vs Totale: €" . number_format($costi_totali, 2) . 
                  " (Diff: €" . number_format($discrepanza, 2) . ")");
    }
    
    // Calcola percentuali (evita divisione per zero)
    $calcola_percentuale = function($valore) use ($costi_totali) {
        return $costi_totali > 0 ? ($valore / $costi_totali) * 100 : 0;
    };
    
    // Restituisce array strutturato identico ai ricavi
    return [
        'centri' => [
            'personale' => [
                'nome' => 'Costi Personale',
                'valore' => $personale,
                'percentuale' => $calcola_percentuale($personale),
                'icona' => 'fas fa-users',
                'colore' => '#dc2626'
            ],
            'produzione' => [
                'nome' => 'Costi Produzione',
                'valore' => $produzione,
                'percentuale' => $calcola_percentuale($produzione),
                'icona' => 'fas fa-industry',
                'colore' => '#ea580c'
            ],
            'it_software' => [
                'nome' => 'IT e Software',
                'valore' => $it_software,
                'percentuale' => $calcola_percentuale($it_software),
                'icona' => 'fas fa-laptop-code',
                'colore' => '#7c3aed'
            ],
            'marketing' => [
                'nome' => 'Marketing',
                'valore' => $marketing,
                'percentuale' => $calcola_percentuale($marketing),
                'icona' => 'fas fa-bullhorn',
                'colore' => '#db2777'
            ],
            'amministrativi' => [
                'nome' => 'Costi Servizi e Consulenze',
                'valore' => $amministrativi,
                'percentuale' => $calcola_percentuale($amministrativi),
                'icona' => 'fas fa-file-invoice',
                'colore' => '#ca8a04'
            ],
            'affitti_utenze' => [
                'nome' => 'Affitti e Utenze',
                'valore' => $affitti_utenze,
                'percentuale' => $calcola_percentuale($affitti_utenze),
                'icona' => 'fas fa-building',
                'colore' => '#0891b2'
            ],
            'oneri_finanziari' => [
                'nome' => 'Oneri Finanziari',
                'valore' => $oneri_finanziari,
                'percentuale' => $calcola_percentuale($oneri_finanziari),
                'icona' => 'fas fa-hand-holding-usd',
                'colore' => '#be123c'
            ],
            'imposte_tasse' => [
                'nome' => 'Imposte e Tasse',
                'valore' => $imposte_tasse,
                'percentuale' => $calcola_percentuale($imposte_tasse),
                'icona' => 'fas fa-hand-holding-usd',
                'colore' => '#be123c'
            ],
            'altri' => [
                'nome' => 'Altri Costi',
                'valore' => $altri,
                'percentuale' => $calcola_percentuale($altri),
                'icona' => 'fas fa-ellipsis-h',
                'colore' => '#6b7280'
            ]
        ],
        'totali' => [
            'somma_centri' => $somma_centri,
            'costi_totali' => $costi_totali,
            'discrepanza' => $discrepanza,
            'coerenza_ok' => $discrepanza <= 1
        ],
        'periodo' => [
            'data_inizio' => $data_inizio,
            'data_fine' => $data_fine
        ]
    ];
}

/**
 * Ottiene i pattern di conti mappati per natura di costo (diretto/indiretto)
 * Restituisce array compatibile con buildWhereClauseConti()
 * 
 * @param PDO $db Connessione database
 * @param string $natura Natura del costo: 'diretto', 'indiretto', 'altro'
 * @param int $cliente_id ID del cliente (default 1)
 * @return array Array di mappature per buildWhereClauseConti()
 */
function getContiByNaturaCosto($db, $natura, $cliente_id = 1) {
    try {
        $query = "
            SELECT DISTINCT mcc.pattern_conto, mcc.is_pattern
            FROM mappatura_conti_cliente mcc
            WHERE mcc.cliente_id = :cliente_id
              AND mcc.natura_costo = :natura
              AND mcc.attivo = 1
            ORDER BY mcc.priorita DESC, mcc.pattern_conto
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':cliente_id' => $cliente_id,
            ':natura' => $natura
        ]);
        
        $patterns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $patterns[] = [
                'pattern' => $row['pattern_conto'],
                'is_pattern' => $row['is_pattern']
            ];
        }
        
        return $patterns;
        
    } catch (PDOException $e) {
        error_log("Errore in getContiByNaturaCosto per natura '$natura': " . $e->getMessage());
        return [];
    }
}

/**
 * Recupera le keyword per i saldi di apertura e chiusura dal database
 * 
 * @param PDO $db Connessione al database
 * @return array Array associativo con chiavi 'apertura' e 'chiusura'
 */
function getKeywordSaldi($db) {
    try {
        $stmt = $db->prepare("SELECT chiave, valore FROM configurazioni_sistema WHERE chiave IN ('KEYWORD_SALDO_APERTURA', 'KEYWORD_SALDO_CHIUSURA')");
        $stmt->execute();
        
        $keywords = [
            'apertura' => 'APERTURA',  // Default
            'chiusura' => 'CHIUSURA'   // Default
        ];
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['chiave'] === 'KEYWORD_SALDO_APERTURA') {
                $keywords['apertura'] = $row['valore'];
            } elseif ($row['chiave'] === 'KEYWORD_SALDO_CHIUSURA') {
                $keywords['chiusura'] = $row['valore'];
            }
        }
        
        return $keywords;
        
    } catch (PDOException $e) {
        error_log("Errore nel recupero keyword saldi: " . $e->getMessage());
        // Ritorna i default in caso di errore
        return ['apertura' => 'APERTURA', 'chiusura' => 'CHIUSURA'];
    }
}

/**
 * Costruisce la clausola SQL per escludere i saldi di apertura e/o chiusura
 * Cerca le keyword in descrizione, causale e annotazioni
 * Verifica anche la data (01/01 per apertura, 31/12 per chiusura)
 * 
 * @param PDO $db Connessione al database
 * @param string $tipo Tipo di esclusione: 'apertura', 'chiusura', 'entrambi' (default)
 * @return string Clausola SQL da concatenare nella WHERE
 */
function buildExcludeSaldiClause($db, $tipo = 'entrambi') {
    $keywords = getKeywordSaldi($db);
    $clausole = [];
    
    // Esclusione saldi di APERTURA
    if ($tipo === 'apertura' || $tipo === 'entrambi') {
        $keyword_apertura = $keywords['apertura'];
        $clausole[] = "
            NOT (
                (descrizione LIKE '%{$keyword_apertura}%' 
                 OR causale LIKE '%{$keyword_apertura}%' 
                 OR annotazioni LIKE '%{$keyword_apertura}%')
                AND MONTH(data_registrazione) = 1 
                AND DAY(data_registrazione) = 1
            )
        ";
    }
    
    // Esclusione saldi di CHIUSURA
    if ($tipo === 'chiusura' || $tipo === 'entrambi') {
        $keyword_chiusura = $keywords['chiusura'];
        $clausole[] = "
            NOT (
                (descrizione LIKE '%{$keyword_chiusura}%' 
                 OR causale LIKE '%{$keyword_chiusura}%' 
                 OR annotazioni LIKE '%{$keyword_chiusura}%')
                AND MONTH(data_registrazione) = 12 
                AND DAY(data_registrazione) = 31
            )
        ";
    }
    
    // Ritorna le clausole unite con AND
    if (empty($clausole)) {
        return '';
    }
    
    return ' AND ' . implode(' AND ', $clausole);
}

?>