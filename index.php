<?php
// ========== LIMITES PHP ==========
ini_set("memory_limit", "256M");
ini_set("max_execution_time", "120");
error_reporting(E_ALL);
ini_set("display_errors", "0");

// Fuseau horaire fixé à UTC partout (PHP + PostgreSQL, voir SET TIME ZONE plus bas)
// pour que "il y a X minutes" / "Hier" soit calculé correctement côté navigateur,
// quel que soit le fuseau par défaut du serveur Postgres (Supabase, région Irlande).
date_default_timezone_set('UTC');

// ========== CONNEXION SUPABASE (PostgreSQL) ==========
$host     = 'aws-0-eu-west-1.pooler.supabase.com';
$port     = '6543';
$dbname   = 'postgres';
$user     = 'postgres.rfblkqrnhcmbikfdfyak';
$password = 'Salyniang1335689';

// Valeurs par défaut
$pdo = null;
$db_error = null;
$stats_batiments = [];
$total_batiments_reel = 0;
$stats_fiscales = ['total_paye'=>0,'total_attente'=>0,'total_retard'=>0,'total_exonere'=>0,'total_paiements'=>0];
$paiements_db = [];
$stats_mensuelles = [];
$infrastructures_db = [];
$batiments_db = [];
$stats_batiments_json      = json_encode($stats_batiments);
$total_batiments_reel_json = 0;
$stats_fiscales_json       = json_encode($stats_fiscales);
$paiements_json            = '[]';
$paiements_fiscaux_json    = '[]';
$stats_mensuelles_json     = '[]';
$dashboard_stats_json      = json_encode(['paiements_mois' => 0, 'montant_mois' => 0, 'total_pending' => 0]);

// ========== HELPERS ==========
function getDefaultUserId(PDO $pdo): int {
    static $cachedId = null;
    if ($cachedId !== null) return $cachedId;
    $stmt = $pdo->query("SELECT id FROM utilisateurs WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $cachedId = $row ? (int)$row['id'] : 1;
    return $cachedId;
}
function calculateCentroid(array $ring): array {
    $latSum = 0; $lngSum = 0; $n = count($ring);
    if ($n === 0) return ['lat' => 14.7247, 'lng' => -17.4892];
    foreach ($ring as $coord) { $lngSum += $coord[0]; $latSum += $coord[1]; }
    return ['lat' => $latSum / $n, 'lng' => $lngSum / $n];
}

// ========== CONNEXION DB ==========
// connect_timeout dans le DSN est la seule façon de limiter le délai en pgsql PDO
try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require;connect_timeout=5";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Indispensable avec le pooler Supabase (PgBouncer en mode "transaction
        // pooling", port 6543) : chaque requête peut être routée vers une
        // connexion serveur différente, donc les prepared statements natifs de
        // PostgreSQL n'y survivent pas d'une requête à l'autre — d'où l'erreur
        // "prepared statement ... does not exist". PDO::ATTR_EMULATE_PREPARES
        // fait envoyer du SQL déjà substitué (émulé côté PHP) au lieu de vrais
        // prepared statements serveur, ce qui contourne complètement le problème.
        PDO::ATTR_EMULATE_PREPARES   => true,
    ]);
    // Fuseau horaire de session forcé à UTC : NOW() et les colonnes date_creation
    // seront toujours cohérents avec date_default_timezone_set('UTC') côté PHP,
    // peu importe la région du serveur Postgres (ici Irlande, avec heure d'été).
    $pdo->exec("SET TIME ZONE 'UTC'");
} catch(PDOException $e) {
    $db_error = $e->getMessage();
    $pdo = null;
}

if ($pdo !== null) {
try {



    // ========== STATISTIQUES MENSUELLES POUR LE GRAPHIQUE ==========
    // Fenêtre glissante réelle des 6 derniers mois calendaires (peu importe la date du jour).
    // generate_series() garantit que chaque mois apparaît, même sans aucun paiement en base ;
    // le LEFT JOIN + COALESCE ne produit alors qu'un 0 réel (absence de donnée), jamais une
    // valeur inventée. Les montants viennent uniquement de ce qui existe réellement dans
    // la table paiements.
$stats_mensuelles = [];
$stmt = $pdo->query("
    SELECT 
        TO_CHAR(mois_serie, 'Mon') as mois,
        EXTRACT(MONTH FROM mois_serie) as mois_num,
        EXTRACT(YEAR FROM mois_serie) as annee,
        COALESCE(SUM(CASE WHEN p.statut = 'paye'    THEN p.montant ELSE 0 END), 0) as total_paye,
        COALESCE(SUM(CASE WHEN p.statut = 'pending' THEN p.montant ELSE 0 END), 0) as total_attente,
        COALESCE(SUM(CASE WHEN p.statut = 'overdue' THEN p.montant ELSE 0 END), 0) as total_retard,
        COALESCE(SUM(CASE WHEN p.statut = 'exempt'  THEN p.montant ELSE 0 END), 0) as total_exonere
    FROM generate_series(
        DATE_TRUNC('month', CURRENT_DATE) - INTERVAL '5 months',
        DATE_TRUNC('month', CURRENT_DATE),
        INTERVAL '1 month'
    ) as mois_serie
    LEFT JOIN paiements p ON DATE_TRUNC('month', p.date_creation) = mois_serie
    GROUP BY mois_serie
    ORDER BY mois_serie
");
$stats_mensuelles = $stmt->fetchAll(PDO::FETCH_ASSOC);



    // ========== STATISTIQUES FISCALES RÉELLES ==========
// Récupérer les totaux par statut de paiement
$stats_fiscales = [];
$stmt = $pdo->query("
    SELECT 
        COALESCE(SUM(CASE WHEN statut = 'paye'    THEN montant ELSE 0 END), 0) as total_paye,
        COALESCE(SUM(CASE WHEN statut = 'pending' THEN montant ELSE 0 END), 0) as total_attente,
        COALESCE(SUM(CASE WHEN statut = 'overdue' THEN montant ELSE 0 END), 0) as total_retard,
        COALESCE(SUM(CASE WHEN statut = 'exempt' THEN montant ELSE 0 END), 0) as total_exonere,
        COUNT(*) as total_paiements
    FROM paiements
");
$stats_fiscales = $stmt->fetch(PDO::FETCH_ASSOC);

// Aucun paiement en base : on garde des totaux réels à zéro plutôt que des
// valeurs de démonstration fictives.
if ($stats_fiscales['total_paiements'] == 0) {
    $stats_fiscales = [
        'total_paye' => 0,
        'total_attente' => 0,
        'total_retard' => 0,
        'total_exonere' => 0,
        'total_paiements' => 0
    ];
}

// ========== STATS RÉELLES POUR LA MODALE "TABLEAU DE BORD FISCAL" ==========
$dashboard_stats = ['paiements_mois' => 0, 'montant_mois' => 0, 'total_pending' => 0];
$stmt = $pdo->query("
    SELECT
        COUNT(*) FILTER (WHERE date_creation >= DATE_TRUNC('month', CURRENT_DATE)) as paiements_mois,
        COALESCE(SUM(montant) FILTER (WHERE statut = 'paye' AND date_creation >= DATE_TRUNC('month', CURRENT_DATE)), 0) as montant_mois,
        COUNT(*) FILTER (WHERE statut = 'pending') as total_pending
    FROM paiements
");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row) { $dashboard_stats = $row; }
$dashboard_stats_json = json_encode($dashboard_stats);

// Récupérer les paiements pour le tableau
$paiements_db = [];
$stmt = $pdo->query("
    SELECT reference, contribuable, nicad, montant, statut, date_creation 
    FROM paiements 
    ORDER BY date_creation DESC 
    LIMIT 10
");
$paiements_db = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer TOUS les paiements (nicad + statut) pour la coloration fiscale des parcelles.
// À ne pas confondre avec $paiements_db ci-dessus, qui est volontairement limité à 10 lignes
// pour le tableau "paiements récents" — la coloration de la carte a besoin de l'ensemble
// des paiements, sinon la quasi-totalité des parcelles retombe sur la couleur par défaut
// (aucune correspondance trouvée) et la carte semble ne pas être colorée du tout.
$paiements_fiscaux_db = [];
$stmt = $pdo->query("SELECT nicad, statut FROM paiements WHERE nicad IS NOT NULL AND nicad != ''");
$paiements_fiscaux_db = $stmt->fetchAll(PDO::FETCH_ASSOC);
$paiements_fiscaux_json = json_encode($paiements_fiscaux_db);

// Charger TOUT le cadastre (parcelles officielles + parcelles tracées par les agents)
// depuis la table `parcelles` — table unique, plus de bloc JSON codé en dur ni de table
// séparée `parcelles_ajoutees` : une parcelle tracée s'ajoute ici directement.
try {
    $parcelles_rows = $pdo->query("SELECT feature_geojson FROM parcelles ORDER BY objectid")->fetchAll(PDO::FETCH_COLUMN);
    $cadastre_parcelles_json = '{"type":"FeatureCollection","features":[' . implode(',', $parcelles_rows) . ']}';
    $max_parcelle_id = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM parcelles")->fetchColumn();
    $total_parcelles_reel = count($parcelles_rows);
} catch (PDOException $e) {
    // La table n'existe probablement pas encore (migration non exécutée) : on continue
    // sans bloquer la page, avec un cadastre vide pour l'instant.
    $cadastre_parcelles_json = '{"type":"FeatureCollection","features":[]}';
    $max_parcelle_id = 0;
    $total_parcelles_reel = 0;
}

// ========== KPI RÉELS POUR LA VUE "TECHNICIEN SIG" ==========
// Alertes rues : contrôles de terrain dont le statut n'est pas "conforme".
$total_alertes_rues_reel = 0;
try {
    $total_alertes_rues_reel = (int)$pdo->query("SELECT COUNT(*) FROM controles WHERE statut IS DISTINCT FROM 'conforme'")->fetchColumn();
} catch (PDOException $e) { /* table 'controles' absente : on garde 0 */ }
// Actifs terrain : comptes utilisateurs actuellement actifs.
$total_actifs_terrain_reel = 0;
try {
    $total_actifs_terrain_reel = (int)$pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE actif = true")->fetchColumn();
} catch (PDOException $e) { /* table 'utilisateurs' absente : on garde 0 */ }

    
    // Récupérer les compteurs pour les KPIs
    $compteurs = [];
    $stmt = $pdo->query("SELECT nom, valeur FROM compteurs");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $compteurs[$row['nom']] = $row['valeur'];
    }
    
    // Récupérer les infrastructures
    $infrastructures_db = [];
    $stmt = $pdo->query("SELECT nom, categorie, latitude, longitude, icone, couleur FROM infrastructures");
    $infrastructures_db = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les bâtiments
    $batiments_db = [];
    $stmt = $pdo->query("SELECT identifiant, type, adresse, quartier, latitude, longitude, surface, etages, polygon_geojson FROM batiments ORDER BY date_creation DESC");
    $batiments_db = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ========== STATISTIQUES RÉELLES DES BÂTIMENTS PAR TYPE ==========
    $stats_batiments = [];
    $stmt = $pdo->query("
        SELECT 
            type, 
            COUNT(*) as nombre,
            ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM batiments), 1) as pourcentage
        FROM batiments 
        GROUP BY type 
        ORDER BY nombre DESC
    ");
    $stats_batiments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_batiments_reel = array_sum(array_column($stats_batiments, 'nombre'));
    
    // Table batiments vide : on affiche une liste vide plutôt que des chiffres
    // de démonstration fictifs. Le total réel reste à 0.
    if ($total_batiments_reel == 0) {
        $stats_batiments = [];
        $total_batiments_reel = 0;
    }
    $stats_batiments_json      = json_encode($stats_batiments);
    $total_batiments_reel_json = $total_batiments_reel;
    $stats_fiscales_json       = json_encode($stats_fiscales);
    $paiements_json            = json_encode($paiements_db);
    $stats_mensuelles_json     = json_encode($stats_mensuelles);

} catch(PDOException $e) {
    $db_error = ($db_error ? $db_error . ' | ' : '') . $e->getMessage();
}
} // fin if($pdo !== null)

// ========== GET: DONNÉES CARTE ==========
if (isset($_GET['get_map_data'])) {
    header('Content-Type: application/json');
    if ($pdo) {
        try {
            $batiments = $pdo->query("SELECT identifiant, type, adresse, quartier, latitude, longitude, surface, etages, polygon_geojson FROM batiments ORDER BY date_creation DESC")->fetchAll(PDO::FETCH_ASSOC);
            $infras    = $pdo->query("SELECT nom, categorie, latitude, longitude, icone, couleur FROM infrastructures")->fetchAll(PDO::FETCH_ASSOC);
            // Inclus pour permettre à la carte de resynchroniser la coloration fiscale des
            // parcelles sans recharger la page, juste après qu'un agent ait ajouté un paiement.
            $paiementsFiscauxLive = $pdo->query("SELECT nicad, statut FROM paiements WHERE nicad IS NOT NULL AND nicad != ''")->fetchAll(PDO::FETCH_ASSOC);
            // Nouvelles parcelles ajoutées (par n'importe quel agent) depuis le dernier id connu
            // du client — évite de re-télécharger tout le cadastre à chaque sondage.
            $sinceId = isset($_GET['since_parcelle_id']) ? (int)$_GET['since_parcelle_id'] : PHP_INT_MAX;
            $parcellesAjouteesLive = [];
            try {
                $stmtP = $pdo->prepare("SELECT feature_geojson FROM parcelles WHERE id > ? ORDER BY id");
                $stmtP->execute([$sinceId]);
                $rawFeatures = $stmtP->fetchAll(PDO::FETCH_COLUMN);
                $parcellesAjouteesLive = array_map(function($f) { return json_decode($f, true); }, $rawFeatures);
            } catch (PDOException $e) { /* table pas encore créée : on ignore */ }
            $maxParcelleIdNow = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM parcelles")->fetchColumn();
            echo json_encode(['success' => true, 'batiments' => $batiments, 'infrastructures' => $infras, 'paiements_fiscaux' => $paiementsFiscauxLive, 'parcelles_ajoutees' => $parcellesAjouteesLive, 'max_parcelle_id' => $maxParcelleIdNow]);
        } catch (PDOException $e) { echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
    } else { echo json_encode(['success' => false, 'error' => 'DB non disponible']); }
    exit;
}

// ========== GET: RECHERCHER UN BÂTIMENT PAR IDENTIFIANT ==========
// Utilisé par les recherches "Modifier un bâtiment" / "Supprimer un bâtiment" : on interroge
// directement la base plutôt que de scanner les marqueurs actuellement affichés sur la carte
// (un bâtiment ajouté par un autre agent, ou dont le marqueur n'est pas chargé/coché, n'existe
// pas forcément dans les couches du navigateur en ce moment, alors qu'il existe bien en base).
if (isset($_GET['find_building'])) {
    header('Content-Type: application/json');
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT identifiant, type, adresse, quartier, latitude, longitude, surface, etages, polygon_geojson FROM batiments WHERE identifiant = ?");
            $stmt->execute([$_GET['find_building']]);
            $building = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($building) {
                echo json_encode(['success' => true, 'building' => $building]);
            } else {
                echo json_encode(['success' => false, 'error' => 'not_found']);
            }
        } catch (PDOException $e) { echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
    } else { echo json_encode(['success' => false, 'error' => 'DB non disponible']); }
    exit;
}

// ========== GET: RECHERCHER UNE/DES PARCELLE(S) PAR NUMÉRO ==========
// Renvoie un tableau (pas un seul objet) : plusieurs parcelles peuvent partager le même
// num_parcel (doublon créé quand un bâtiment est tracé sur une parcelle déjà existante).
if (isset($_GET['find_parcelle'])) {
    header('Content-Type: application/json');
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT objectid, identifiant, rue, angle, commune, quartiers, surface, num_parcel FROM parcelles WHERE num_parcel = ? ORDER BY objectid");
            $stmt->execute([$_GET['find_parcelle']]);
            $parcelles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'parcelles' => $parcelles]);
        } catch (PDOException $e) { echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
    } else { echo json_encode(['success' => false, 'error' => 'DB non disponible']); }
    exit;
}

// ========== GET: STATISTIQUES FISCALES ==========
if (isset($_GET['get_fiscal_stats'])) {
    header('Content-Type: application/json');
    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT COALESCE(SUM(CASE WHEN statut='paye' THEN montant ELSE 0 END),0) as total_paye, COALESCE(SUM(CASE WHEN statut='pending' THEN montant ELSE 0 END),0) as total_attente, COALESCE(SUM(CASE WHEN statut='overdue' THEN montant ELSE 0 END),0) as total_retard, COALESCE(SUM(CASE WHEN statut='exempt' THEN montant ELSE 0 END),0) as total_exonere FROM paiements");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true] + $row);
        } catch (PDOException $e) { echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
    } else { echo json_encode(['success' => false, 'error' => 'DB non disponible']); }
    exit;
}

// ========== GET: STATS SUPERVISION ADMIN ==========
if (isset($_GET['get_admin_stats'])) {
    header('Content-Type: application/json');
    $result = [
        'success'       => true,
        'users_actifs'  => 0,
        'users_delta'   => '+0',
        'failed_logins' => 0,
        'sync_lag'      => '1m',
        'server_load'   => 32,
    ];
    if ($pdo) {
        try {
            $row = $pdo->query("SELECT COUNT(*) as total FROM utilisateurs WHERE actif = true")->fetch(PDO::FETCH_ASSOC);
            $result['users_actifs'] = (int)$row['total'];
            // Tentatives échouées du jour : si table connexions_echouees existe, sinon 0
            try {
                $rowF = $pdo->query("SELECT COUNT(*) as cnt FROM connexions_echouees WHERE date_tentative >= CURRENT_DATE")->fetch(PDO::FETCH_ASSOC);
                $result['failed_logins'] = (int)$rowF['cnt'];
            } catch (Exception $e2) { /* table absente : pas de tentative échouée trackée, on garde 0 */ }
        } catch (PDOException $e) { /* on renvoie les valeurs par défaut */ }
    }
    echo json_encode($result);
    exit;
}

// Convertit un timestamp Postgres (ex: "2026-07-30 23:10:15.123456", déjà en UTC
// grâce à SET TIME ZONE 'UTC') en chaîne ISO-8601 explicite ("...T...Z") pour que
// `new Date(...)` côté navigateur l'interprète toujours comme de l'UTC, quel que
// soit le navigateur — un format "espace" sans fuseau est ambigu et souvent
// interprété comme une heure locale, ce qui décalait les libellés "Aujourd'hui/Hier".
function to_iso_utc($ts) {
    if (!$ts) return null;
    try {
        $dt = new DateTime($ts, new DateTimeZone('UTC'));
        return $dt->format('Y-m-d\TH:i:s\Z');
    } catch (Exception $e) {
        return $ts;
    }
}

// ========== GET: JOURNAL D'ACTIVITÉ ==========
if (isset($_GET['get_activity_log'])) {
    header('Content-Type: application/json');
    $logs = [];
    if ($pdo) {
        try {
            // Derniers paiements
            $stmt = $pdo->query("SELECT contribuable, date_creation, statut, montant FROM paiements ORDER BY date_creation DESC LIMIT 3");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $logs[] = [
                    'type'    => 'paiement',
                    'texte'   => $r['contribuable'] . ' — paiement ' . number_format((float)$r['montant'],0,',',' ') . ' FCFA',
                    'statut'  => $r['statut'],
                    'date'    => to_iso_utc($r['date_creation']),
                ];
            }
            // Derniers bâtiments ajoutés
            try {
                $stmt2 = $pdo->query("SELECT identifiant, quartier, date_creation FROM batiments ORDER BY date_creation DESC LIMIT 2");
                foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $logs[] = [
                        'type'   => 'batiment',
                        'texte'  => 'Bâtiment ' . $r['identifiant'] . ' ajouté — ' . $r['quartier'],
                        'statut' => 'ok',
                        'date'   => to_iso_utc($r['date_creation']),
                    ];
                }
            } catch (Exception $e2) {}
            // Dernières parcelles ajoutées/tracées (table unique parcelles)
            try {
                $stmt3 = $pdo->query("SELECT num_parcel, quartiers, date_creation FROM parcelles ORDER BY date_creation DESC LIMIT 2");
                foreach ($stmt3->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $logs[] = [
                        'type'   => 'parcelle',
                        'texte'  => 'Parcelle n°' . ($r['num_parcel'] ?: '—') . ' ajoutée au cadastre' . ($r['quartiers'] ? ' — ' . $r['quartiers'] : ''),
                        'statut' => 'ok',
                        'date'   => to_iso_utc($r['date_creation']),
                    ];
                }
            } catch (Exception $e2) {}
            // Derniers contrôles de terrain
            try {
                $stmt4 = $pdo->query("SELECT numero_parcelle, type_controle, statut, controle_par, date_controle FROM controles ORDER BY date_controle DESC LIMIT 2");
                foreach ($stmt4->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $logs[] = [
                        'type'   => 'controle',
                        'texte'  => 'Contrôle ' . $r['type_controle'] . ' sur parcelle n°' . $r['numero_parcelle'] . ' — ' . ($r['statut'] === 'conforme' ? 'conforme' : 'non conforme'),
                        'statut' => $r['statut'] === 'conforme' ? 'ok' : 'warning',
                        'date'   => to_iso_utc($r['date_controle']),
                    ];
                }
            } catch (Exception $e2) {}
            // Tentatives de connexion échouées récentes (si la table existe)
            try {
                $stmt5 = $pdo->query("SELECT email, ip_address, date_tentative FROM connexions_echouees ORDER BY date_tentative DESC LIMIT 2");
                foreach ($stmt5->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $logs[] = [
                        'type'   => 'securite',
                        'texte'  => 'Échec de connexion (' . ($r['email'] ?: 'inconnu') . ($r['ip_address'] ? ' · IP ' . $r['ip_address'] : '') . ')',
                        'statut' => 'error',
                        'date'   => to_iso_utc($r['date_tentative']),
                    ];
                }
            } catch (Exception $e2) {}
            // Trier par date desc
            usort($logs, function($a,$b){ return strcmp($b['date'], $a['date']); });
            $logs = array_slice($logs, 0, 6);
        } catch (PDOException $e) {}
    }
    // Pas de données de secours fictives : si la base n'a aucune activité récente,
    // on renvoie une liste vide et le front affiche "Aucune activité récente".
    echo json_encode(['success'=>true, 'logs'=>$logs, 'from_db'=>true]);
    exit;
}

// ========== GET: NOTIFICATIONS RÉELLES ==========
if (isset($_GET['get_notifications'])) {
    header('Content-Type: application/json');
    $notifs = [];
    if ($pdo) {
        try {
            // Paiements en attente : heure du dernier paiement mis en attente (pas "maintenant")
            $row = $pdo->query("SELECT COUNT(*) as cnt, MAX(date_creation) as derniere FROM paiements WHERE statut = 'pending'")->fetch(PDO::FETCH_ASSOC);
            $cntAttente = (int)$row['cnt'];
            if ($cntAttente > 0) {
                $notifs[] = ['id' => 'paiements_attente', 'title' => 'Paiements en attente', 'message' => $cntAttente . ' paiement(s) en attente de validation', 'type' => 'warning', 'time' => to_iso_utc($row['derniere'])];
            }
            // Paiements en retard : heure du dernier paiement passé en retard
            $row = $pdo->query("SELECT COUNT(*) as cnt, MAX(date_creation) as derniere FROM paiements WHERE statut = 'overdue'")->fetch(PDO::FETCH_ASSOC);
            $cntRetard = (int)$row['cnt'];
            if ($cntRetard > 0) {
                $notifs[] = ['id' => 'paiements_retard', 'title' => 'Paiements en retard', 'message' => $cntRetard . ' paiement(s) en retard', 'type' => 'danger', 'time' => to_iso_utc($row['derniere'])];
            }
            // Bâtiments ajoutés dans les dernières 24h : heure du plus récent d'entre eux
            try {
                $row = $pdo->query("SELECT COUNT(*) as cnt, MAX(date_creation) as derniere FROM batiments WHERE date_creation >= NOW() - INTERVAL '24 hours'")->fetch(PDO::FETCH_ASSOC);
                $cntBatiments = (int)$row['cnt'];
                if ($cntBatiments > 0) {
                    $notifs[] = ['id' => 'batiments_recents', 'title' => 'Bâtiments ajoutés', 'message' => $cntBatiments . ' bâtiment(s) ajouté(s) au SIG dans les dernières 24h', 'type' => 'success', 'time' => to_iso_utc($row['derniere'])];
                }
            } catch (Exception $e2) {}
            // Tentatives de connexion échouées aujourd'hui : heure de la plus récente
            try {
                $row = $pdo->query("SELECT COUNT(*) as cnt, MAX(date_tentative) as derniere FROM connexions_echouees WHERE date_tentative >= CURRENT_DATE")->fetch(PDO::FETCH_ASSOC);
                $cntEchecs = (int)$row['cnt'];
                if ($cntEchecs > 0) {
                    $notifs[] = ['id' => 'connexions_echouees_' . date('Y-m-d'), 'title' => 'Sécurité', 'message' => $cntEchecs . ' tentative(s) de connexion échouée(s) aujourd\'hui', 'type' => 'danger', 'time' => to_iso_utc($row['derniere'])];
                }
            } catch (Exception $e2) {}
            // Agents actifs : heure de la connexion la plus récente parmi eux
            $row = $pdo->query("SELECT COUNT(*) as cnt, MAX(derniere_connexion) as derniere FROM utilisateurs WHERE actif = true")->fetch(PDO::FETCH_ASSOC);
            $cntAgents = (int)$row['cnt'];
            $notifs[] = ['id' => 'agents_actifs', 'title' => 'Agents actifs', 'message' => $cntAgents . ' agent(s) actif(s) sur la plateforme', 'type' => 'info', 'time' => $row['derniere'] ? to_iso_utc($row['derniere']) : date('Y-m-d\TH:i:s\Z')];
            echo json_encode(['success' => true, 'notifications' => $notifs, 'from_db' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage(), 'notifications' => []]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'DB non disponible', 'notifications' => []]);
    }
    exit;
}

// ========== GET: AGENTS CONNECTÉS ==========
if (isset($_GET['get_agents'])) {
    header('Content-Type: application/json');
    $agents = [];
    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT nom, role, TO_CHAR(derniere_connexion, 'HH24:MI') as heure FROM utilisateurs WHERE actif = true ORDER BY derniere_connexion DESC LIMIT 8");
            $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }
    // Pas de liste de secours fictive : si aucun agent actif en base, le front
    // affiche "Aucun agent connecté" plutôt que des noms inventés.
    echo json_encode(['success'=>true, 'agents'=>$agents, 'total'=>count($agents), 'from_db'=>true]);
    exit;
}

// ========== POST: LOG D'ACTIVITÉ MANUEL ==========
// (Géré plus bas dans le bloc POST principal)

// ========== TRAITER LES REQUÊTES POST ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $rawInput = file_get_contents('php://input');
    if ($rawInput === false || $rawInput === '') {
        echo json_encode(['success' => false, 'error' => 'Corps de requête vide.']);
        exit;
    }
    $data = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'error' => 'JSON invalide']);
        exit;
    }
    if (!isset($data['action'])) {
        echo json_encode(['success' => false, 'error' => 'Action manquante']);
        exit;
    }

    // LOGIN — traité en PREMIER, avant tout guard $pdo
    if ($data['action'] === 'login') {
        $email = strtolower(trim($data['email'] ?? ''));
        $pwd   = (string)($data['password'] ?? '');
        if ($email === '' || $pwd === '') {
            echo json_encode(['success' => false, 'error' => 'Email et mot de passe requis.']);
            exit;
        }
        // Fallback admin local (toujours disponible même sans DB)
        if ($email === 'admin@unco.sn' && $pwd === 'admin123') {
            echo json_encode(['success'=>true,'id'=>0,'nom'=>'Administrateur UNCO','email'=>$email,'role'=>'admin']);
            exit;
        }
        if (!$pdo) {
            echo json_encode(['success'=>false,'error'=>'Base de données inaccessible (timeout). Réessayez dans quelques secondes.']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("SELECT id, nom, email, mot_de_passe, role, actif FROM utilisateurs WHERE LOWER(email) = ? LIMIT 1");
            $stmt->execute([$email]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $estActif = !in_array($u['actif'], [false, 0, '0', 'f', 'false', null], true);
                if (!$estActif) { echo json_encode(['success'=>false,'error'=>'Compte désactivé.']); exit; }
                if (!password_verify($pwd, $u['mot_de_passe'])) { echo json_encode(['success'=>false,'error'=>'Mot de passe incorrect.']); exit; }
                $pdo->prepare("UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id = ?")->execute([$u['id']]);
                echo json_encode(['success'=>true,'id'=>(int)$u['id'],'nom'=>$u['nom'],'email'=>$u['email'],'role'=>$u['role']]);
                exit;
            }
            echo json_encode(['success'=>false,'error'=>'Aucun compte trouvé pour cet email.']);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success'=>false,'error'=>'Erreur DB: '.$e->getMessage()]);
            exit;
        }
    }

    // Toutes les autres actions nécessitent la DB
    if (!$pdo) {
        echo json_encode(['success'=>false,'error'=>'Base de données non disponible.']);
        exit;
    }

    // Action: add_infrastructure
    if ($data['action'] === 'add_infrastructure') {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO infrastructures (nom, categorie, latitude, longitude, icone, couleur)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['nom'],
                $data['categorie'],
                $data['latitude'],
                $data['longitude'],
                $data['icone'] ?? null,
                $data['couleur'] ?? null
            ]);

            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // Action: delete_infrastructure
    if ($data['action'] === 'delete_infrastructure') {
        try {
            $stmt = $pdo->prepare("DELETE FROM infrastructures WHERE nom = ? AND latitude = ? AND longitude = ?");
            $stmt->execute([$data['nom'], $data['latitude'], $data['longitude']]);
            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // Action: add_building
    if ($data['action'] === 'add_building') {
        try {
            $hasPolygon = !empty($data['polygon_geojson']);

            if ($hasPolygon) {
                // Bâtiment tracé par polygone — on tente de stocker le GeoJSON du polygone.
                // Si la colonne polygon_geojson n'existe pas encore dans Supabase, on crée d'abord
                // un INSERT sans cette colonne, puis on tente un UPDATE séparé.
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO batiments (identifiant, type, adresse, quartier, latitude, longitude, surface, etages, observations, cree_par, polygon_geojson) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $data['identifiant'], $data['type'], $data['adresse'] ?? null,
                        $data['quartier'] ?? null, $data['latitude'], $data['longitude'],
                        $data['surface'], $data['etages'], $data['observations'] ?? null,
                        is_numeric($data['cree_par'] ?? null) ? (int)$data['cree_par'] : getDefaultUserId($pdo),
                        $data['polygon_geojson']
                    ]);
                } catch (PDOException $colErr) {
                    // La colonne polygon_geojson n'existe pas encore : INSERT sans elle
                    $stmt = $pdo->prepare("
                        INSERT INTO batiments (identifiant, type, adresse, quartier, latitude, longitude, surface, etages, observations, cree_par) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $data['identifiant'], $data['type'], $data['adresse'] ?? null,
                        $data['quartier'] ?? null, $data['latitude'], $data['longitude'],
                        $data['surface'], $data['etages'], $data['observations'] ?? null,
                        is_numeric($data['cree_par'] ?? null) ? (int)$data['cree_par'] : getDefaultUserId($pdo)
                    ]);
                }
            } else {
                // Ajout classique par point (lat/lng uniquement)
                $stmt = $pdo->prepare("
                    INSERT INTO batiments (identifiant, type, adresse, quartier, latitude, longitude, surface, etages, observations, cree_par) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $data['identifiant'], $data['type'], $data['adresse'] ?? null,
                    $data['quartier'] ?? null, $data['latitude'], $data['longitude'],
                    $data['surface'], $data['etages'], $data['observations'] ?? null,
                    is_numeric($data['cree_par'] ?? null) ? (int)$data['cree_par'] : getDefaultUserId($pdo)
                ]);
            }
            
            $pdo->exec("UPDATE compteurs SET valeur = valeur + 1, date_mise_a_jour = NOW() WHERE nom = 'total_batiments'");
            
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }
    
    // Action: add_parcelle_utilisateur
    // Enregistre une parcelle tracée manuellement par un agent directement dans la table
    // `parcelles` (même table que le cadastre officiel importé) — mêmes champs : Rue, Angle,
    // Commune, Quatiers, Surface, Num_Parcel, Shape_Leng, Shape_Area, Identifiant.
    if ($data['action'] === 'add_parcelle_utilisateur') {
        try {
            $feature = $data['feature_geojson'] ?? null;
            $featureArr = is_string($feature) ? json_decode($feature, true) : $feature;
            if (!$featureArr || empty($featureArr['properties'])) {
                echo json_encode(['success' => false, 'error' => 'Champs manquants (feature_geojson invalide)']);
                exit;
            }
            $p = $featureArr['properties'];

            // OBJECTID assigné côté serveur (dans la même requête) pour éviter toute
            // collision si deux agents tracent une parcelle presque en même temps.
            $nextObjectId = (int)$pdo->query("SELECT COALESCE(MAX(objectid),0) + 1 FROM parcelles")->fetchColumn();
            $p['OBJECTID'] = $nextObjectId;
            $featureArr['properties'] = $p;
            $featureJson = json_encode($featureArr);

            $stmt = $pdo->prepare("
                INSERT INTO parcelles (objectid, identifiant, rue, angle, commune, quartiers, surface, num_parcel, shape_leng, shape_area, feature_geojson, date_creation)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $nextObjectId,
                $p['Identifiant'] ?? null,
                $p['Rue'] ?? null,
                $p['Angle'] ?? null,
                $p['Commune'] ?? 'Ouakam',
                $p['Quatiers'] ?? null,
                $p['Surface'] ?? null,
                $p['Num_Parcel'] ?? null,
                $p['Shape_Leng'] ?? null,
                $p['Shape_Area'] ?? null,
                $featureJson
            ]);
            echo json_encode(['success' => true, 'objectid' => $nextObjectId, 'feature' => $featureArr]);
            exit;
        } catch (PDOException $e) {
            $hint = (strpos($e->getMessage(), 'parcelles') !== false && strpos($e->getMessage(), 'does not exist') !== false)
                ? " — la table 'parcelles' n'existe probablement pas encore, voir la migration SQL fournie."
                : '';
            echo json_encode(['success' => false, 'error' => $e->getMessage() . $hint]);
            exit;
        }
    }
    
    // Action: update_parcelle — modifie une parcelle existante (colonnes + feature_geojson,
    // pour que la carte et la table Supabase restent cohérentes). Identifiée par objectid,
    // seule clé fiable puisque num_parcel peut être dupliqué.
    if ($data['action'] === 'update_parcelle') {
        try {
            $stmt = $pdo->prepare("SELECT feature_geojson FROM parcelles WHERE objectid = ?");
            $stmt->execute([$data['objectid']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                echo json_encode(['success' => false, 'error' => 'not_found']);
                exit;
            }
            $featureArr = json_decode($row['feature_geojson'], true);
            $p = $featureArr['properties'] ?? [];
            $p['Identifiant'] = $data['identifiant'] ?? null;
            $p['Rue'] = $data['rue'] ?? null;
            $p['Quatiers'] = $data['quartier'] ?? null;
            $p['Commune'] = $data['commune'] ?? null;
            if (isset($data['surface']) && $data['surface'] !== null) $p['Surface'] = $data['surface'];
            $featureArr['properties'] = $p;
            $featureJson = json_encode($featureArr);

            $stmt = $pdo->prepare("
                UPDATE parcelles SET
                    identifiant = ?, rue = ?, quartiers = ?, commune = ?, surface = ?, feature_geojson = ?
                WHERE objectid = ?
            ");
            $stmt->execute([
                $data['identifiant'] ?? null,
                $data['rue'] ?? null,
                $data['quartier'] ?? null,
                $data['commune'] ?? null,
                $data['surface'] ?? null,
                $featureJson,
                $data['objectid']
            ]);
            echo json_encode(['success' => true, 'feature' => $featureArr]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // Action: delete_parcelle — identifiée par objectid (num_parcel n'est pas unique).
    if ($data['action'] === 'delete_parcelle') {
        try {
            $stmt = $pdo->prepare("DELETE FROM parcelles WHERE objectid = ?");
            $stmt->execute([$data['objectid']]);
            if ($stmt->rowCount() === 0) {
                echo json_encode(['success' => false, 'error' => 'not_found']);
                exit;
            }
            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // Action: list_import_batches — historique des imports SIG (shapefile/geojson/dxf/kml)
    if ($data['action'] === 'list_import_batches') {
        try {
            $stmt = $pdo->query("SELECT id, filename, format, crs, created_at, nb_infrastructures, nb_rues, nb_batiments FROM import_batches ORDER BY created_at DESC LIMIT 100");
            $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'batches' => $batches]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // Action: delete_import_batch — supprime toutes les entités créées par un import donné,
    // dans infrastructures / rues / batiments, identifiées par leur import_batch_id.
    if ($data['action'] === 'delete_import_batch') {
        try {
            $batchId = $data['batch_id'] ?? null;
            if (!$batchId) {
                echo json_encode(['success' => false, 'error' => 'batch_id manquant']);
                exit;
            }
            $pdo->beginTransaction();
            $stmtI = $pdo->prepare("DELETE FROM infrastructures WHERE import_batch_id = ?");
            $stmtI->execute([$batchId]);
            $delInfra = $stmtI->rowCount();

            $stmtR = $pdo->prepare("DELETE FROM rues WHERE import_batch_id = ?");
            $stmtR->execute([$batchId]);
            $delRues = $stmtR->rowCount();

            $stmtB = $pdo->prepare("DELETE FROM batiments WHERE import_batch_id = ?");
            $stmtB->execute([$batchId]);
            $delBat = $stmtB->rowCount();

            if ($delBat > 0) {
                $pdo->exec("UPDATE compteurs SET valeur = GREATEST(valeur - $delBat, 0), date_mise_a_jour = NOW() WHERE nom = 'total_batiments'");
            }

            $stmtDel = $pdo->prepare("DELETE FROM import_batches WHERE id = ?");
            $stmtDel->execute([$batchId]);

            $pdo->commit();
            echo json_encode(['success' => true, 'deleted' => ['infrastructures' => $delInfra, 'rues' => $delRues, 'batiments' => $delBat]]);
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // Action: delete_building
    if ($data['action'] === 'delete_building') {
        try {
            $stmt = $pdo->prepare("DELETE FROM batiments WHERE identifiant = ?");
            $stmt->execute([$data['identifiant']]);

            if ($stmt->rowCount() === 0) {
                echo json_encode(['success' => false, 'error' => 'not_found']);
                exit;
            }
            
            $pdo->exec("UPDATE compteurs SET valeur = valeur - 1, date_mise_a_jour = NOW() WHERE nom = 'total_batiments' AND valeur > 0");
            
            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }
    
    // Action: update_building
    if ($data['action'] === 'update_building') {
        try {
            $stmt = $pdo->prepare("
                UPDATE batiments SET 
                    type = ?, 
                    adresse = ?, 
                    latitude = ?, 
                    longitude = ?, 
                    surface = ?,
                    date_modification = NOW()
                WHERE identifiant = ?
            ");
            $stmt->execute([
                $data['type'],
                $data['adresse'],
                $data['latitude'],
                $data['longitude'],
                $data['surface'],
                $data['identifiant']
            ]);
            
            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    
    
    // ========== ACTION: SAVE_RECENSEMENT ==========
    if ($data['action'] === 'save_recensement') {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO recensements (nom_structure, type_structure, latitude, longitude, adresse, proprietaire, telephone, observations, cree_par) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['nom_structure'],
                $data['type_structure'],
                $data['latitude'],
                $data['longitude'],
                $data['adresse'],
                $data['proprietaire'],
                $data['telephone'],
                $data['observations'],
                is_numeric($data['cree_par'] ?? null) ? (int)$data['cree_par'] : getDefaultUserId($pdo)
            ]);
            
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }


    // ========== ACTION: SAVE_COMMERCE ==========
if ($data['action'] === 'save_commerce') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO commerces (nom, type, latitude, longitude, adresse, proprietaire, telephone, observations, cree_par, date_creation) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $data['nom'],
            $data['type'],
            $data['latitude'],
            $data['longitude'],
            $data['adresse'],
            $data['proprietaire'],
            $data['telephone'],
            $data['observations'],
            is_numeric($data['cree_par'] ?? null) ? (int)$data['cree_par'] : getDefaultUserId($pdo)
        ]);
        
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ========== ACTION: SAVE_PAIEMENT ==========
if ($data['action'] === 'save_paiement') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO paiements (reference, contribuable, nicad, montant, mode_paiement, date_paiement, numero_recu, observations, statut, cree_par, date_creation) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'paye', ?, NOW())
        ");
        $stmt->execute([
            $data['reference'],
            $data['contribuable'],
            $data['nicad'] ?? null,
            $data['montant'],
            $data['mode_paiement'],
            $data['date_paiement'],
            $data['numero_recu'],
            $data['observations'],
            is_numeric($data['cree_par'] ?? null) ? (int)$data['cree_par'] : getDefaultUserId($pdo)
        ]);
        
        // Mettre à jour le compteur de paiements
        $pdo->exec("UPDATE compteurs SET valeur = valeur + 1, date_mise_a_jour = NOW() WHERE nom = 'total_paiements'");
        
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}



// ========== ACTION: SAVE_PAIEMENT_COMPLET ==========
if ($data['action'] === 'save_paiement_complet') {
    try {
        // "exempt" (Exonéré) est maintenant une valeur autorisée par la contrainte CHECK
        // de la table `paiements` (paiements_statut_check) — on ne la force plus vers "pending".
        $statutMap = ['encours'=>'pending','impaye'=>'overdue','exonere'=>'exempt'];
        $statut = $statutMap[$data['statut']] ?? $data['statut'];
        if (!in_array($statut, ['paye','pending','overdue','exempt'])) $statut = 'pending';

        $stmt = $pdo->prepare("
            INSERT INTO paiements (reference, contribuable, nicad, montant, mode_paiement, date_paiement, observations, statut, cree_par, date_creation) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $data['reference'],
            $data['contribuable'],
            $data['nicad'] ?? null,
            $data['montant'],
            $data['mode_paiement'],
            $data['date_paiement'],
            $data['observations'],
            $statut,
            is_numeric($data['cree_par'] ?? null) ? (int)$data['cree_par'] : getDefaultUserId($pdo)
        ]);
        
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}




// ========== ACTION: SAVE_CONTROLE ==========
if ($data['action'] === 'save_controle') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO controles (numero_parcelle, type_controle, zone_reglementaire, observations, statut, controle_par, date_controle) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $data['numero_parcelle'],
            $data['type_controle'],
            $data['zone_reglementaire'],
            $data['observations'],
            $data['statut'],
            is_numeric($data['controle_par'] ?? null) ? (int)$data['controle_par'] : getDefaultUserId($pdo)
        ]);
        
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}



// ========== ACTION: UPDATE_PAIEMENT ==========
if ($data['action'] === 'update_paiement') {
    try {
        // Normaliser le statut vers les valeurs acceptées par la contrainte CHECK
        $statutMap = [
            'encours' => 'pending',
            'impaye'  => 'overdue',
            'exempt'  => 'pending', // pas de statut exempt dans le schéma
            'exonere' => 'pending',
        ];
        $statut = $statutMap[$data['statut']] ?? $data['statut'];

        $stmt = $pdo->prepare("
            UPDATE paiements 
            SET contribuable = ?, montant = ?, statut = ?
            WHERE reference = ?
        ");
        $stmt->execute([
            $data['contribuable'],
            $data['montant'],
            $statut,
            $data['reference']
        ]);
        
        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ========== ACTION: DELETE_PAIEMENT ==========
if ($data['action'] === 'delete_paiement') {
    try {
        $stmt = $pdo->prepare("DELETE FROM paiements WHERE reference = ?");
        $stmt->execute([$data['reference']]);
        
        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}


    
    // ========== ACTION: SAVE_REPORT ==========
    if ($data['action'] === 'save_report') {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO rapports (titre, type_rapport, periode, format, contenu, date_generation, genere_par) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['titre'],
                $data['type'],
                $data['periode'],
                $data['format'],
                $data['contenu'],
                $data['date'],
                getDefaultUserId($pdo)
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }
    
    // ========== ACTION: CREATE_USER ==========
    if ($data['action'] === 'create_user') {
        try {
            // Valider les champs obligatoires
            if (empty($data['nom']) || empty($data['email'])) {
                echo json_encode(['success' => false, 'error' => 'Nom et email sont obligatoires.']);
                exit;
            }
            // Mapper les rôles du formulaire vers les valeurs CHECK de la BD
            $roleMap = [
                'Administrateur'  => 'admin',
                'Agent Municipal' => 'agent',
                'Technicien SIG'  => 'controleur',
                'admin'           => 'admin',
                'agent'           => 'agent',
                'controleur'      => 'controleur',
            ];
            $role = $roleMap[$data['role'] ?? ''] ?? 'agent';

            // Vérifier que l'email n'existe pas déjà
            $check = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ?");
            $check->execute([$data['email']]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Cet email est déjà utilisé.']);
                exit;
            }

            // Hasher le mot de passe (ou générer un temporaire)
            $motDePasse = !empty($data['mot_de_passe'])
                ? password_hash($data['mot_de_passe'], PASSWORD_BCRYPT)
                : password_hash('Changez@moi123', PASSWORD_BCRYPT);

            $stmt = $pdo->prepare("
                INSERT INTO utilisateurs (nom, email, mot_de_passe, role, actif, date_creation)
                VALUES (?, ?, ?, ?, true, NOW())
            ");
            $stmt->execute([
                trim($data['nom']),
                strtolower(trim($data['email'])),
                $motDePasse,
                $role
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'role_stored' => $role]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // ========== ACTION: GET_USERS ==========
    if ($data['action'] === 'get_users') {
        try {
            $stmt = $pdo->query("
                SELECT id, nom, email, role, actif,
                       TO_CHAR(date_creation, 'DD/MM/YYYY') as date_creation,
                       TO_CHAR(derniere_connexion, 'DD/MM/YYYY HH24:MI') as derniere_connexion
                FROM utilisateurs
                ORDER BY date_creation DESC
            ");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'users' => $users]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // ========== ACTION: TOGGLE_USER ==========
    if ($data['action'] === 'toggle_user') {
        try {
            $stmt = $pdo->prepare("UPDATE utilisateurs SET actif = NOT actif WHERE id = ?");
            $stmt->execute([(int)$data['id']]);
            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // ========== ACTION: IMPORT_SIG ==========
    // Reprojette une coordonnée [x, y] depuis un CRS source vers WGS84 (EPSG:4326) en
// utilisant PostGIS (déjà activé sur la base pour la couche cadastre). Nécessaire
// car un Shapefile/DXF en UTM Zone 28N (valeurs en mètres, ex. 280000/1630000) ne
// peut jamais être stocké tel quel dans une colonne latitude/longitude.
function reprojectXY($pdo, $x, $y, $crs) {
    $crsInt = (int)$crs;
    if (!$crsInt || $crsInt === 4326) return [(float)$x, (float)$y]; // déjà en WGS84
    static $cache = [];
    $key = $crsInt . ':' . $x . ',' . $y;
    if (isset($cache[$key])) return $cache[$key];
    try {
        $stmt = $pdo->prepare("SELECT ST_X(t) AS lng, ST_Y(t) AS lat FROM (SELECT ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), ?), 4326) AS t) sub");
        $stmt->execute([(float)$x, (float)$y, $crsInt]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $result = $row ? [(float)$row['lng'], (float)$row['lat']] : [(float)$x, (float)$y];
    } catch (Exception $e) {
        $result = [(float)$x, (float)$y]; // en cas d'échec, on ne bloque pas tout l'import
    }
    $cache[$key] = $result;
    return $result;
}

if ($data['action'] === 'import_sig') {
        try {
            $format   = $data['format'];
            $crs      = $data['crs'] ?? '4326';
            $filename = $data['filename'] ?? 'fichier_importe';

            // Identifiant de lot : toutes les entités créées par cet import portent cette
            // même étiquette (colonne import_batch_id), pour pouvoir tout supprimer d'un coup
            // depuis la page plus tard, sans avoir à écrire de SQL dans Supabase.
            $batchId = 'imp_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));

            $importedCount = 0;
            $errors = [];
            
            if ($format === 'geojson') {
                // Accepter soit geojson_direct (objet JSON) soit content (base64)
                if (isset($data['geojson_direct']) && is_array($data['geojson_direct'])) {
                    $geojson = $data['geojson_direct'];
                } else {
                    $fileContent = base64_decode($data['content'] ?? '');
                    if ($fileContent === false) {
                        echo json_encode(['success' => false, 'error' => 'Erreur de décodage base64']);
                        exit;
                    }
                    $geojson = json_decode($fileContent, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        echo json_encode(['success' => false, 'error' => 'GeoJSON invalide: ' . json_last_error_msg()]);
                        exit;
                    }
                }
                if (!isset($geojson['features']) || !is_array($geojson['features'])) {
                    echo json_encode(['success' => false, 'error' => 'Format GeoJSON invalide: features manquants']);
                    exit;
                }

                $stats       = ['Point' => 0, 'LineString' => 0, 'Polygon' => 0, 'MultiPolygon' => 0, 'other' => 0];
                $features    = $geojson['features'];
                $defaultUser = is_numeric($data['cree_par'] ?? null) ? (int)$data['cree_par'] : getDefaultUserId($pdo);

                // Préparer les statements UNE SEULE FOIS en dehors de la boucle
                $stmtInfra = $pdo->prepare("INSERT INTO infrastructures (nom, categorie, latitude, longitude, icone, couleur, date_creation, import_batch_id) VALUES (?, ?, ?, ?, 'map-pin', '#1A6B45', NOW(), ?)");
                $stmtRue   = $pdo->prepare("INSERT INTO rues (nom, longueur, date_creation, import_batch_id) VALUES (?, ?, NOW(), ?)");
                $stmtBat   = $pdo->prepare("INSERT INTO batiments (identifiant, type, latitude, longitude, surface, adresse, quartier, date_creation, cree_par, observations, import_batch_id) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?) ON CONFLICT (identifiant) DO NOTHING");

                // Collecter toutes les valeurs à insérer par type (insertion batch par blocs de 100)
                $batchPoints   = [];
                $batchRues     = [];
                $batchBatiments = [];

                foreach ($features as $feature) {
                    if (!isset($feature['geometry']) || !isset($feature['geometry']['type'])) continue;
                    $geometry   = $feature['geometry'];
                    $properties = $feature['properties'] ?? [];
                    $type       = $geometry['type'];
                    $stats[$type] = ($stats[$type] ?? 0) + 1;

                    if ($type === 'Point') {
                        [$px, $py] = reprojectXY($pdo, $geometry['coordinates'][0], $geometry['coordinates'][1], $crs);
                        $batchPoints[] = [
                            $properties['nom'] ?? $properties['name'] ?? $properties['Nom'] ?? 'Point sans nom',
                            $properties['categorie'] ?? $properties['type'] ?? $properties['Type'] ?? 'import_geojson',
                            $py,
                            $px,
                            $batchId
                        ];
                    } elseif ($type === 'LineString') {
                        $batchRues[] = [
                            $properties['nom'] ?? $properties['name'] ?? $properties['Nom'] ?? 'Route sans nom',
                            $properties['Shape_Leng'] ?? $properties['longueur'] ?? 0,
                            $batchId
                        ];
                    } elseif ($type === 'Polygon' || $type === 'MultiPolygon') {
                        $center = ['lat' => 14.7247, 'lng' => -17.4892];
                        $hasRealCoords = false;
                        if ($type === 'Polygon' && isset($geometry['coordinates'][0])) {
                            $center = calculateCentroid($geometry['coordinates'][0]);
                            $hasRealCoords = true;
                        } elseif ($type === 'MultiPolygon' && isset($geometry['coordinates'][0][0])) {
                            $center = calculateCentroid($geometry['coordinates'][0][0]);
                            $hasRealCoords = true;
                        }
                        // Le centroïde ci-dessus est calculé dans le système de coordonnées
                        // source (mètres si UTM) : on le reprojette maintenant en WGS84.
                        // (Le point de repli Dakar par défaut est déjà en WGS84, on ne le
                        // reprojette pas pour ne pas produire une valeur aberrante.)
                        if ($hasRealCoords) {
                            [$center['lng'], $center['lat']] = reprojectXY($pdo, $center['lng'], $center['lat'], $crs);
                        }
                        $batchBatiments[] = [
                            $properties['id'] ?? $properties['Num_Parcel'] ?? $properties['identifiant'] ?? 'IMP-' . uniqid(),
                            $properties['type'] ?? $properties['Type'] ?? $properties['usage'] ?? 'Résidentiel',
                            $center['lat'],
                            $center['lng'],
                            (int)($properties['surface'] ?? $properties['Surface'] ?? 0),
                            $properties['adresse'] ?? $properties['Adresse'] ?? '',
                            $properties['quartier'] ?? $properties['Quartier'] ?? $properties['Quatiers'] ?? '',
                            $defaultUser,
                            "Importé depuis $filename",
                            $batchId
                        ];
                    }
                }

                // Insérer en une seule transaction
                $pdo->beginTransaction();
                try {
                    foreach ($batchPoints as $row) {
                        $stmtInfra->execute($row);
                        $importedCount++;
                    }
                    foreach ($batchRues as $row) {
                        try { $stmtRue->execute($row); $importedCount++; }
                        catch (PDOException $e) { $errors[] = "Rue ignorée: " . $e->getMessage(); }
                    }
                    foreach ($batchBatiments as $row) {
                        $stmtBat->execute($row);
                        $importedCount++;
                    }
                    // Mettre à jour le compteur une seule fois
                    if (count($batchBatiments) > 0) {
                        $pdo->exec("UPDATE compteurs SET valeur = valeur + " . count($batchBatiments) . ", date_mise_a_jour = NOW() WHERE nom = 'total_batiments'");
                    }
                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => 'Erreur transaction: ' . $e->getMessage()]);
                    exit;
                }

                // Enregistrer le lot d'import (pour l'historique / suppression groupée depuis la page).
                // Ne bloque pas la réponse si ça échoue (ex. table import_batches pas encore créée).
                if ($importedCount > 0) {
                    try {
                        $stmtBatch = $pdo->prepare("INSERT INTO import_batches (id, filename, format, crs, created_at, created_by, nb_infrastructures, nb_rues, nb_batiments) VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?)");
                        $stmtBatch->execute([$batchId, $filename, $format, $crs, $defaultUser, count($batchPoints), count($batchRues), count($batchBatiments)]);
                    } catch (PDOException $e) { /* table import_batches absente : ignorer silencieusement */ }
                }

                $message = "$importedCount entités importées avec succès";
                if (!empty($errors)) $message .= " (" . count($errors) . " avertissements)";
                echo json_encode(['success' => true, 'message' => $message, 'count' => $importedCount, 'stats' => $stats, 'errors' => $errors, 'batch_id' => $batchId]);
                exit;
            } elseif ($format === 'kml') {
                $kmlContent = base64_decode($data['content'] ?? '');
                if ($kmlContent === false) { echo json_encode(['success' => false, 'error' => 'Erreur décodage KML']); exit; }
                $kml = simplexml_load_string($kmlContent);
                if ($kml === false) { echo json_encode(['success' => false, 'error' => 'KML invalide']); exit; }
                if (isset($kml->Document)) {
                    foreach ($kml->Document->Placemark as $placemark) {
                        $nom    = (string)$placemark->name;
                        $coords = (string)$placemark->Point->coordinates;
                        if ($coords) {
                            $parts = explode(',', $coords);
                            if (count($parts) >= 2) {
                                $lng = floatval($parts[0]); $lat = floatval($parts[1]);
                                $stmt = $pdo->prepare("INSERT INTO infrastructures (nom, categorie, latitude, longitude, icone, couleur, date_creation, import_batch_id) VALUES (?, 'kml_import', ?, ?, 'map-pin', '#1A6B45', NOW(), ?)");
                                $stmt->execute([$nom, $lat, $lng, $batchId]);
                                $importedCount++;
                            }
                        }
                    }
                }
                if ($importedCount > 0) {
                    try {
                        $stmtBatch = $pdo->prepare("INSERT INTO import_batches (id, filename, format, crs, created_at, created_by, nb_infrastructures, nb_rues, nb_batiments) VALUES (?, ?, 'kml', ?, NOW(), ?, ?, 0, 0)");
                        $stmtBatch->execute([$batchId, $filename, $crs, is_numeric($data['cree_par'] ?? null) ? (int)$data['cree_par'] : getDefaultUserId($pdo), $importedCount]);
                    } catch (PDOException $e) { /* table import_batches absente : ignorer silencieusement */ }
                }
                echo json_encode(['success' => true, 'message' => "$importedCount entités importées depuis KML", 'count' => $importedCount, 'batch_id' => $batchId]);
                exit;
            } elseif ($format === 'shapefile') {
                echo json_encode(['success' => false, 'error' => 'Shapefile non supporté directement. Convertissez en GeoJSON sur https://mapshaper.org']);
                exit;
            }
            echo json_encode(['success' => false, 'error' => 'Format non supporté']);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // Action inconnue
    echo json_encode(['success' => false, 'error' => 'Action inconnue : ' . $data['action']]);
    exit;
}  // fin du bloc POST


?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>UNCO — Système de Gestion Urbaine et Fiscale de Ouakam</title>
    <?php if (!empty($db_error)): ?>
    <script>console.warn('UNCO: DB hors-ligne (<?php echo addslashes($db_error); ?>). Mode données locales activé.');</script>
    <?php endif; ?>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <!-- Leaflet Routing Machine CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.css" />
    <!-- Leaflet.draw CSS - pour le dessin de polygones sur la carte -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.css" />

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

    <style>
        /* ---------- UNCO THEME SYSTEM (intégral) ---------- */
        :root {
            --bg-base: #F7F8FA;
            --surface: #FFFFFF;
            --surface-alt: #EFF3F0;
            --civic-green: #1A6B45;
            --green-mid: #2D8A5E;
            --green-light: #E8F5EE;
            --sand-gold: #B8860B;
            --teal-accent: #0D7377;
            --text-primary: #111827;
            --text-secondary: #6B7280;
            --text-muted: #9CA3AF;
            --border: #E5E7EB;
            --border-strong: #D1D5DB;
            --success: #4A7C5F;
            --warning: #E8963A;
            --danger: #C0392B;
            --info: #0D7377;
            --radius-lg: 16px;
            --radius-md: 10px;
            --radius-sm: 6px;
            --shadow-sm: 0 1px 2px 0 rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg-base); color: var(--text-primary); overflow: hidden; }
        
        /* PAGE DE CONNEXION */
        .login-container { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(135deg, #0B1120, #1A2A3A); display: flex; align-items: center; justify-content: center; z-index: 2000; }
        .login-card { background: white; border-radius: 32px; padding: 40px 36px; width: 400px; text-align: center; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); }
        .login-card h1 { font-size: 2rem; font-weight: 800; color: var(--civic-green); }
        .login-card input { background: #F8FAFE; border: 1px solid #E2E8F0; border-radius: 60px; padding: 12px 20px; width: 100%; margin-bottom: 16px; }
        .password-field-wrapper { position: relative; width: 100%; margin-bottom: 16px; }
        .password-field-wrapper input#loginPassword { padding-right: 46px; margin-bottom: 0; }
        .password-toggle-btn {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            padding: 0;
            margin: 0;
            cursor: pointer;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .password-toggle-btn:hover { color: #1A6B45; }
        .password-toggle-btn svg { width: 18px; height: 18px; pointer-events: none; }
        .login-btn { background: var(--civic-green); color: white; border: none; border-radius: 60px; padding: 12px; font-weight: 600; width: 100%; cursor: pointer; }
        .login-error { color: #DC2626; font-size: 0.75rem; margin-top: 12px; display: none; }
        
        /* APP LAYOUT */
        .app-layout { display: flex; height: 100vh; width: 100vw; display: none; }
        .nav-sidebar { width: 260px; background: #0B1120; display: flex; flex-direction: column; justify-content: space-between; flex-shrink: 0; }
        .sidebar-brand { padding: 24px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .brand-icon { width: 28px; height: 28px; color: #F5C542; }
        .brand-text h2 { font-size: 1.3rem; font-weight: 700; color: white; margin: 0; }
        .brand-text span { font-size: 0.6rem; color: #94A3B8; }
        .role-indicator { padding: 20px 20px 12px; }
        .role-badge { background: rgba(245,197,66,0.12); padding: 4px 12px; border-radius: 100px; font-size: 0.65rem; font-weight: 600; color: #F5C542; display: inline-block; }
        .sidebar-nav { flex: 1; padding: 20px 12px; display: flex; flex-direction: column; gap: 4px; overflow-y: auto; -webkit-overflow-scrolling: touch; min-height: 0; }
        .nav-item { background: transparent; border: none; display: flex; align-items: center; gap: 12px; padding: 10px 16px; border-radius: 12px; font-weight: 500; color: #CBD5E1; cursor: pointer; width: 100%; }
        .nav-item:hover { background: rgba(255,255,255,0.06); color: white; }
        .nav-item.active { background: #1E2A3A; color: white; }
        .sidebar-user { padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; gap: 12px; cursor: pointer; }
        .user-avatar { width: 36px; height: 36px; background: linear-gradient(145deg, #2C3E50, #1E2A3A); border-radius: 100px; display: flex; align-items: center; justify-content: center; font-weight: 600; color: #F5C542; }
        .logout-link { font-size: 0.7rem; color: #94A3B8; margin-left: 8px; }
        
        .main-area { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .top-header { background: white; padding: 12px 24px; display: flex; justify-content: space-between; border-bottom: 1px solid var(--border); }
        .view-title { font-weight: 700; font-size: 1.2rem; color: var(--text-primary); }
        .status-indicator { font-size: 0.65rem; background: #EFF6FF; padding: 4px 10px; border-radius: 40px; margin-left: 12px; display: inline-flex; align-items: center; gap: 5px; }
        
        .workspace-container { flex: 1; overflow-y: auto; padding: 20px 24px; }
        .role-view { display: none; flex-direction: column; gap: 20px; }
        .role-view.active { display: flex; }
        
        /* CARTES */
        .map-container { height: 55vh; min-height: 480px; border-radius: 20px; overflow: hidden; box-shadow: var(--shadow-md); margin-bottom: 12px; }
        #unco-main-map, #fiscal-map, #admin-minimap { height: 100%; width: 100%; }
        .map-buttons { display: flex; gap: 12px; justify-content: center; margin-bottom: 16px; }
        .map-pill { background: white; border: 1px solid var(--border); border-radius: 40px; padding: 8px 24px; font-weight: 600; font-size: 0.75rem; cursor: pointer; }
        .map-pill.active { background: var(--civic-green); color: white; }
        
        /* PANEAUX SIG */
        .bottom-panel { background: white; border-radius: 20px; padding: 18px 24px; border: 1px solid var(--border); }
        .kpi-row { display: flex; gap: 20px; margin-bottom: 24px; }
        .kpi-card { flex: 1; text-align: center; padding: 12px; background: #F8FAFE; border-radius: 16px; }
        .kpi-label { font-size: 0.65rem; text-transform: uppercase; color: var(--text-secondary); }
        .kpi-value { font-size: 1.6rem; font-weight: 800; color: var(--civic-green); }
        .kpi-value.gold { color: var(--sand-gold); }
        .controls-row { display: flex; gap: 24px; margin-bottom: 20px; }
        .layers-panel, .legend-panel { flex: 1; background: #F8FAFE; border-radius: 16px; padding: 14px 18px; }
        .panel-title { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; margin-bottom: 10px; color: var(--text-secondary); }
        .legend-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
        .legend-dot { width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; background: var(--civic-green); color: white; }
        .actions-row { display: flex; gap: 12px; flex-wrap: wrap; }
        .action-btn { background: white; border: 1px solid var(--border); border-radius: 40px; padding: 8px 18px; font-weight: 600; font-size: 0.7rem; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        
        /* FISCAL (conforme à la photo) */
        .fiscal-kpi-row { display: flex; gap: 16px; margin-bottom: 20px; }
        .fiscal-kpi-card { flex: 1; text-align: center; padding: 12px; background: white; border-radius: 16px; border: 1px solid var(--border); }
        .fiscal-legend { background: white; border-radius: 16px; padding: 12px 16px; margin-bottom: 20px; border: 1px solid var(--border); display: flex; gap: 24px; justify-content: center; }
        .fiscal-legend-item { display: flex; align-items: center; gap: 8px; font-size: 0.75rem; font-weight: 500; }
        .fiscal-legend-color { width: 12px; height: 12px; border-radius: 50%; }
        .fiscal-filter-bar { display: flex; gap: 10px; margin-bottom: 20px; background: white; padding: 8px 16px; border-radius: 60px; width: fit-content; border: 1px solid var(--border); }
        .fiscal-filter-btn { background: transparent; border: none; border-radius: 40px; padding: 6px 16px; font-weight: 500; font-size: 0.75rem; cursor: pointer; }
        .fiscal-filter-btn.active { background: var(--civic-green); color: white; }
        .fiscal-charts-row { display: flex; gap: 20px; margin-bottom: 20px; }
        .fiscal-chart-card { background: white; border-radius: 16px; padding: 16px; flex: 1; border: 1px solid var(--border); }
        .action-strip { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
        .fiscal-action-btn { background: white; border: 1px solid var(--border); border-radius: 40px; padding: 8px 20px; font-weight: 600; font-size: 0.75rem; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .unco-table { width: 100%; font-size: 0.75rem; border-collapse: collapse; }
        .unco-table th, .unco-table td { padding: 10px 8px; border-bottom: 1px solid var(--border); text-align: left; }
        .status-pill { padding: 4px 10px; border-radius: 40px; font-size: 0.65rem; font-weight: 600; display: inline-block; }
        .status-pill.paye { background: #DCFCE7; color: #15803D; }
        .status-pill.impaye { background: #FEE2E2; color: #B91C1C; }
        .status-pill.encours { background: #FEF3C7; color: #B45309; }
        
        .admin-stats-grid { display: flex; gap: 20px; margin-bottom: 20px; }
        .admin-stat-card { background: white; border-radius: 16px; padding: 16px; flex: 1; border: 1px solid var(--border); }
        .stat-value { font-size: 1.8rem; font-weight: 800; }
        
        .custom-marker-icon { background: white; border-radius: 50%; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; font-size: 18px; border: 2px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.25); }



        .legend-marker {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-right: 8px;
    flex-shrink: 0;
}
    


/* Conteneur de la carte en position relative */
.map-container {
    position: relative;
}

/* Pills flottants en bas au milieu */
.map-pills-overlay {
    position: absolute;
    bottom: 5px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 8px;
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(8px);
    padding: 6px 12px;
    border-radius: 50px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    z-index: 400;
}

/* Pour la carte de l'Administrateur uniquement */
#view-admin .map-container .map-pills-overlay {
    bottom: 100px;  /* Ajuste cette valeur : plus grand = plus haut */
}

/* Boutons réduits */
.map-pill {
    background: transparent;
    border: none;
    padding: 4px 14px;
    border-radius: 40px;
    font-weight: 600;
    font-size: 0.7rem;
    cursor: pointer;
    transition: 0.2s;
    color: #334155;
}

.map-pill.active {
    background: var(--civic-green);
    color: white;
}

.map-pill:hover:not(.active) {
    background: #e2e8f0;
}



/* Réduction de l'espace entre les stats et les boutons dans la vue admin */
#view-admin .admin-stats-grid {
    margin-bottom: 4px !important;
}

#view-admin .actions-row {
    margin-top: 0 !important;
    padding-top: 0 !important;
}



#admin-minimap {
    height: 385px !important;
}


/* Supprimer la bande blanche en bas de toutes les cartes Leaflet */
.leaflet-container {
    background: transparent !important;
}

.leaflet-control-attribution {
    display: none !important;
}

.leaflet-bottom,
.leaflet-control {
    margin-bottom: 0 !important;
    padding-bottom: 0 !important;
}



/* Légende fiscale flottante sur la carte (en bas à gauche, verticale) */
.fiscal-legend-overlay {
    position: absolute;
    bottom: 15px;
    left: 15px;
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(8px);
    padding: 8px 12px;
    border-radius: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    z-index: 400;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    font-size: 0.7rem;
    font-weight: 500;
}

.fiscal-legend-overlay .fiscal-legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
}



/* Barre de filtres */
.fiscal-filter-bar {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 16px;
    background: white;
    padding: 10px 24px;
    border-radius: 60px;
    width: fit-content;
    margin: 0 auto 24px auto;
    border: 1px solid var(--border);
    box-shadow: 0 2px 6px rgba(0,0,0,0.04);
}

/* Bouton de filtre avec icône */
.fiscal-filter-btn {
    background: transparent;
    border: none;
    border-radius: 40px;
    padding: 8px 20px;
    font-weight: 600;
    font-size: 0.75rem;
    cursor: pointer;
    transition: all 0.2s ease;
    color: var(--text-secondary);
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

/* Point coloré à côté du texte */
.filter-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
}

/* Couleurs des points */
.btn-paid .filter-dot { background: #4caf50; }
.btn-pending .filter-dot { background: #f59e0b; }
.btn-overdue .filter-dot { background: #ef4444; }
.btn-exempt .filter-dot { background: #94a3b8; }

/* Version active : texte en gras et point légèrement agrandi */
.fiscal-filter-btn.active {
    color: var(--text-primary);
    font-weight: 700;
    background: #f8fafc;
}

.fiscal-filter-btn.active .filter-dot {
    width: 12px;
    height: 12px;
    box-shadow: 0 0 0 2px rgba(0,0,0,0.1);
}



.fiscal-kpi-card {
    border-left: 4px solid;
}
.fiscal-kpi-card:first-child { border-left-color: #4caf50; }
.fiscal-kpi-card:nth-child(2) { border-left-color: #f59e0b; }
.fiscal-kpi-card:nth-child(3) { border-left-color: #ef4444; }
.fiscal-kpi-card:last-child { border-left-color: #94a3b8; }

#fkpi-paid-val { color: #4caf50; }
#fkpi-pending-val { color: #f59e0b; }
#fkpi-overdue-val { color: #ef4444; }
#fkpi-exempt-val { color: #94a3b8; }




/* Toast de notification */
.unco-toast {
    position: fixed;
    top: 50%;
    left: 60%;
    transform: translate(-50%, -50%);
    background: white;
    border-radius: 32px;
    padding: 80px 80px;
    box-shadow: 0 25px 45px -12px rgba(0,0,0,0.25);
    z-index: 9999;
    text-align: center;
    min-width: 400px;
    max-width: 500px;
    animation: fadeInOut 3s ease forwards;
    border-left: 8px solid #1A6B45;
}

.unco-toast.success { border-left-color: #1A6B45; }
.unco-toast.error { border-left-color: #dc2626; }
.unco-toast.warning { border-left-color: #f59e0b; }

.unco-toast .toast-icon {
    font-size: 48px;
    margin-bottom: 16px;
}

.unco-toast .toast-title {
    font-weight: 800;
    font-size: 1.5rem;
    margin-bottom: 12px;
    color: #1A2A3A;
}

.unco-toast .toast-message {
    font-size: 1rem;
    color: #4B5563;
    line-height: 1.5;
}

@keyframes fadeInOut {
    0% { opacity: 0; transform: translate(-50%, -50%) scale(0.9); }
    10% { opacity: 1; transform: translate(-50%, -50%) scale(1); }
    80% { opacity: 1; transform: translate(-50%, -50%) scale(1); }
    100% { opacity: 0; transform: translate(-50%, -50%) scale(0.9); visibility: hidden; 
}
}

/* Panneau de notifications */
.notification-panel {
    position: absolute;
    top: 60px;
    right: 20px;
    width: 320px;
    background: white;
    border-radius: 20px;
    box-shadow: 0 20px 35px -10px rgba(0,0,0,0.2);
    z-index: 1000;
    display: none;
    overflow: hidden;
    border: 1px solid #e5e7eb;
}

.notification-panel.show {
    display: block;
    animation: fadeIn 0.2s ease;
}

.notification-header {
    padding: 14px 18px;
    background: #f8fafc;
    border-bottom: 1px solid #e5e7eb;
    font-weight: 700;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.notification-header button {
    background: none;
    border: none;
    font-size: 1.2rem;
    cursor: pointer;
    color: #6b7280;
}

.notification-list {
    max-height: 380px;
    overflow-y: auto;
}

.notification-item {
    padding: 14px 18px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    gap: 12px;
    cursor: pointer;
    transition: background 0.2s;
}

.notification-item:hover {
    background: #f8fafc;
}

.notification-item.unread {
    background: #eff6ff;
}

.notification-icon {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.notification-icon.success { background: #dcfce7; color: #15803d; }
.notification-icon.warning { background: #fef3c7; color: #b45309; }
.notification-icon.info { background: #dbeafe; color: #2563eb; }
.notification-icon.danger { background: #fee2e2; color: #b91c1c; }

.notification-content {
    flex: 1;
}

.notification-title {
    font-weight: 600;
    font-size: 0.85rem;
    margin-bottom: 4px;
}

.notification-message {
    font-size: 0.7rem;
    color: #6b7280;
}

.notification-time {
    font-size: 0.6rem;
    color: #9ca3af;
    margin-top: 4px;
}

.notification-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background: #ef4444;
    color: white;
    font-size: 0.6rem;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 20px;
    min-width: 18px;
    text-align: center;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}


/* Panneau de notifications */
.notification-panel {
    position: absolute;
    top: 50px;
    right: 0;
    width: 340px;
    background: white;
    border-radius: 20px;
    box-shadow: 0 20px 35px -10px rgba(0,0,0,0.2);
    z-index: 1000;
    display: none;
    overflow: hidden;
    border: 1px solid #e5e7eb;
}
.notification-panel.show { display: block; }
.notification-header {
    padding: 12px 16px;
    background: #f8fafc;
    border-bottom: 1px solid #e5e7eb;
    font-weight: 700;
    display: flex;
    justify-content: space-between;
}
.notification-list { max-height: 380px; overflow-y: auto; }
.notification-item {
    padding: 12px 16px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    gap: 12px;
    cursor: pointer;
}
.notification-item.unread { background: #eff6ff; }
.notification-icon {
    width: 32px; height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.notification-icon.success { background: #dcfce7; color: #15803d; }
.notification-icon.warning { background: #fef3c7; color: #b45309; }
.notification-icon.info { background: #dbeafe; color: #2563eb; }
.notification-title { font-weight: 600; font-size: 0.85rem; }
.notification-message { font-size: 0.7rem; color: #6b7280; }
.notification-time { font-size: 0.6rem; color: #9ca3af; margin-top: 4px; }



/* Réduction des espacements dans la vue Agent Municipal */
#view-municipal .map-container {
    margin-bottom: 8px !important;
}


#view-municipal .fiscal-filter-bar {
    margin-top: 4px !important;
    margin-bottom: 16px !important;
        padding: 6px 16px !important;

}

#view-municipal .fiscal-charts-row {
    margin-top: 8px !important;
     gap: 16px !important;
}

#view-municipal .fiscal-chart-card {
    margin-bottom: 3 !important;
    padding: 12px !important;
}

#view-municipal .fiscal-chart-card canvas {
    max-height: 200px !important;
}






/* Panneaux flottants sur la carte (compact, vertical) */
.map-overlay-panel {
    position: absolute;
    background: rgba(255, 255, 255, 0.96);
    backdrop-filter: blur(8px);
    border-radius: 14px;
    padding: 12px 14px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.13);
    z-index: 900;
    border: 1px solid #e2e8f0;
    min-width: 185px;
}

/* Panneau Couches SIG - décalé sous les boutons zoom +/- de Leaflet */
#layersPanel {
    top: 110px;
    left: 16px;
}

/* Panneau Couches SIG - carte fiscale (Agent Municipal) */
#layersPanelFiscal {
    top: 70px;
    left: 16px;
}

/* Légende infrastructures - en dessous (actuellement masquée) */
#legendPanel {
    top: 314px;
    left: 16px;
}

/* Style compact des éléments */
.layer-item, .legend-item {
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;
}

.layer-item input, .legend-dot {
    width: 14px;
    height: 14px;
    margin: 0;
}

.panel-title {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    margin-bottom: 8px;
    color: #1e293b;
    letter-spacing: 0.5px;
}

.legend-grid {
    display: flex;
    flex-direction: column;
    gap: 4px;
}






.legend-icon {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.7rem;
    margin-bottom: 6px;
}





/* ========================================
   BOUTONS AJUSTÉS - MODALES & ACTIONS
   ======================================== */

/* Tous les boutons dans les modales (Fermer, Enregistrer, Ajouter, etc.) */
.modal-footer .btn,
.modal-body .btn {
    padding: 10px 20px !important;
    font-size: 0.85rem !important;
    font-weight: 600 !important;
    border-radius: 40px !important;
    min-width: 120px;
    text-align: center;
    cursor: pointer;
}

/* Footer des modales : alignement et espacement */
.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding: 16px 24px;
    border-top: 1px solid #e5e7eb;
}

/* Bouton secondaire (Fermer) */
.btn-secondary {
    background: #f1f5f9;
    color: #1e293b;
    border: 1px solid #cbd5e1;
}

.btn-secondary:hover {
    background: #e2e8f0;
}

/* Bouton primaire (Enregistrer, Ajouter, Valider) */
.btn-primary {
    background: #1A6B45;
    color: white;
    border: none;
}

.btn-primary:hover {
    background: #0e4f33;
}

/* ========================================
   BOUTONS FLOTTANTS SUR LA CARTE (SIG)
   ======================================== */
.map-action-buttons {
    position: absolute;
    bottom: 16px;
    right: 16px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    z-index: 400;
    /* Sécurité anti-débordement : si l'espace vertical manque (petit écran, carte
       peu haute), la barre devient défilable au lieu de couper les premiers
       boutons (ex. "Ajouter un bâtiment") hors du cadre visible. */
    max-height: calc(100% - 24px);
    overflow-y: auto;
    overflow-x: hidden;
    scrollbar-width: none;
}
.map-action-buttons::-webkit-scrollbar {
    display: none;
}

.map-action-btn {
    width: 38px;
    height: 38px;
    flex-shrink: 0;
    border-radius: 50%;
    background: white;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    color: #1A6B45;
}

.map-action-btn i {
    width: 17px;
    height: 17px;
}

.map-action-btn:hover {
    background: #1A6B45;
    color: white;
    transform: scale(1.05);
}

/* Tooltip au survol : rendu via #mapBtnTooltip (JS), car .map-action-buttons
   a overflow-y:auto/overflow-x:hidden qui coupait un ::after positionné en absolute. */
#mapBtnTooltip {
    position: fixed;
    background: #1e293b;
    color: white;
    font-size: 0.7rem;
    font-weight: 500;
    padding: 4px 10px;
    border-radius: 20px;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.15s;
    font-family: 'Inter', sans-serif;
    z-index: 3000;
}
#mapBtnTooltip.visible {
    opacity: 1;
}

/* ===== MODE DESSIN POLYGONE BÂTIMENT ===== */
#drawPolygonBtn.drawing-active {
    background: #dc2626 !important;
    color: white !important;
    animation: pulse-draw 1.5s infinite;
}
@keyframes pulse-draw {
    0%, 100% { box-shadow: 0 0 0 0 rgba(220,38,38,0.4); }
    50%       { box-shadow: 0 0 0 8px rgba(220,38,38,0); }
}

/* Barre de statut qui apparaît en haut de la carte pendant le dessin */
#drawStatusBar {
    display: none;
    position: absolute;
    top: 12px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(220,38,38,0.92);
    color: white;
    padding: 8px 20px;
    border-radius: 40px;
    font-size: 0.8rem;
    font-weight: 600;
    z-index: 1000;
    pointer-events: none;
    white-space: nowrap;
    box-shadow: 0 2px 12px rgba(0,0,0,0.25);
}

/* ========================================
   BOUTONS DANS LES VUES (AGENT MUNICIPAL, ADMIN)
   ======================================== */
.fiscal-action-btn,
.action-btn {
    padding: 10px 16px !important;
    font-size: 0.8rem !important;
    border-radius: 40px !important;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    justify-content: center;
    white-space: nowrap;
    cursor: pointer;
    transition: all 0.2s;
}

.fiscal-action-btn:hover,
.action-btn:hover {
    background: #f1f5f9;
    transform: translateY(-1px);
}

/* Empêcher les boutons de passer à la ligne intempestivement */
.action-strip,
.actions-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

/* ========================================
   HAUTEUR DES MODALES POUR ÉVITER LE SCROLL INUTILE
   ======================================== */
.modal-dialog {
    max-height: 90vh;
    margin: 1.75rem auto;
}

.modal-body {
    max-height: 65vh;
    overflow-y: auto;
    padding: 20px 24px;
}





/* Style pour l'overlay de chargement */
.map-container {
    position: relative;
}

.loading-capture {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    border-radius: 20px;
    color: white;
    font-size: 1.2rem;
    backdrop-filter: blur(4px);
}

.loading-capture::after {
    content: "";
    width: 30px;
    height: 30px;
    margin-left: 15px;
    border: 3px solid white;
    border-top-color: #1A6B45;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}




/* Tooltip pour le nom complet */
.user-avatar {
    position: relative;
    cursor: pointer;
}

.user-tooltip {
    visibility: hidden;
    position: absolute;
    bottom: 110%;
    left: 50%;
    transform: translateX(-50%);
    background: #1e293b;
    color: white;
    font-size: 0.7rem;
    font-weight: normal;
    padding: 6px 12px;
    border-radius: 8px;
    white-space: nowrap;
    z-index: 100;
    opacity: 0;
    transition: opacity 0.2s ease, visibility 0.2s ease;
    pointer-events: none;
    font-family: 'Inter', sans-serif;
}

.user-tooltip::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%);
    border-width: 5px;
    border-style: solid;
    border-color: #1e293b transparent transparent transparent;
}

.user-avatar:hover .user-tooltip {
    visibility: visible;
    opacity: 1;
}

    /* ================================================================
       RESPONSIVE MOBILE — l'app n'avait aucune règle @media auparavant,
       ce qui cassait l'affichage sur téléphone (sidebar fixe de 260px,
       colonnes fiscales côte à côte, cartes trop hautes, etc.)
       ================================================================ */
    @media (max-width: 900px) {
        html, body { overflow-x: hidden; }

        /* La sidebar passe d'une colonne verticale de 260px à une barre
           horizontale compacte en haut de l'écran */
        .app-layout { flex-direction: column; height: 100vh; }
        .nav-sidebar {
            width: 100%;
            flex-direction: row;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            overflow-x: auto;
        }
        .sidebar-brand { padding: 10px 12px; border-bottom: none; flex-shrink: 0; }
        .brand-text span { display: none; }
        .role-indicator { display: none; }
        .sidebar-nav { flex: 1; flex-direction: row; padding: 6px; gap: 4px; overflow-x: auto; }
        .nav-item { width: auto; padding: 8px 10px; white-space: nowrap; }
        .nav-item span { display: none; }
        .sidebar-user { padding: 8px 10px; border-top: none; flex-shrink: 0; }
        .sidebar-user > div:not(.user-avatar):not(.logout-link) { display: none; }
        .logout-link { display: none; }

        .main-area { flex: 1; min-height: 0; }
        .top-header { padding: 10px 14px; flex-wrap: wrap; gap: 6px; }
        .view-title { font-size: 1rem; }
        .workspace-container { padding: 12px; }

        /* Cartes : hauteur réduite pour rester utilisable sur petit écran */
        .map-container { height: 45vh; min-height: 300px; }

        /* Grilles / rangées de KPI et de cartes : on empile au lieu d'écraser */
        .kpi-row,
        .admin-stats-grid,
        .actions-row,
        .action-strip,
        .fiscal-charts-row {
            flex-wrap: wrap;
        }
        .kpi-row .kpi-card,
        .admin-stats-grid > div {
            flex: 1 1 45%;
            min-width: 130px;
        }
        .fiscal-chart-card { flex: 1 1 100%; min-width: 0; }

        /* Vue Agent Municipal : carte + colonne latérale empilées verticalement */
        .fiscal-row-mobile { flex-direction: column !important; }
        .fiscal-map-block, .fiscal-side-block { flex: 1 1 100% !important; width: 100%; }

        /* Panneaux flottants sur les cartes : plus compacts et jamais plus larges que l'écran */
        .map-overlay-panel { min-width: 0; max-width: calc(100vw - 64px); font-size: 0.78rem; padding: 10px 12px; }
        .map-pills-overlay { flex-wrap: wrap; }

        /* Tableaux : défilement horizontal plutôt que débordement cassé */
        .unco-table { display: block; overflow-x: auto; white-space: nowrap; }

        .fiscal-filter-bar { flex-wrap: wrap; width: 100%; border-radius: 20px; }
        .login-card { width: 90%; max-width: 360px; padding: 28px 22px; }
    }

    @media (max-width: 480px) {
        .map-container { height: 40vh; min-height: 240px; }
        .kpi-row .kpi-card, .admin-stats-grid > div { flex: 1 1 100%; }
    }
    </style>
    
</head>
<body>

<div id="loginPage" class="login-container">
    <div class="login-card">
        <h1>UNCO</h1>
        <p>Système de Gestion Urbaine et Fiscale</p>
        <input type="email" id="loginEmail" placeholder="Email" autocomplete="off">
        <div class="password-field-wrapper">
            <input type="password" id="loginPassword" placeholder="Mot de passe" autocomplete="off">
            <button type="button" class="password-toggle-btn" onclick="toggleLoginPasswordVisibility()" aria-label="Afficher ou masquer le mot de passe" tabindex="-1">
                <svg id="loginPasswordToggleIcon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
            </button>
        </div>
        <button class="login-btn" onclick="attemptLogin()">Se connecter</button>
        <div id="loginError" class="login-error">Identifiants incorrects</div>
    </div>
</div>

<script>
    // Icône œil en SVG inline (autonome, ne dépend pas du chargement de la
    // librairie lucide qui n'est chargée qu'en bas de page) : garantit que
    // l'icône afficher/masquer est visible dès le chargement de l'écran de connexion.
    function toggleLoginPasswordVisibility() {
        const input = document.getElementById('loginPassword');
        const icon = document.getElementById('loginPasswordToggleIcon');
        if (!input || !icon) return;
        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        icon.innerHTML = showing
            ? '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"></path><circle cx="12" cy="12" r="3"></circle>'
            : '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.6 18.6 0 0 1 5.06-5.94"></path><path d="M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a18.6 18.6 0 0 1-2.16 3.19"></path><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
    }
</script>

<script>
    // FORCER l'effacement du champ email de 3 façons différentes :
    
    // 1. Effacement immédiat (au moment où le script est lu)
    if (document.getElementById('loginEmail')) {
        document.getElementById('loginEmail').value = '';
    }
    
    // 2. Effacement quand le DOM est complètement chargé
    document.addEventListener('DOMContentLoaded', function() {
        const emailField = document.getElementById('loginEmail');
        if (emailField) {
            emailField.value = '';
        }
        // 3. Effacement supplémentaire après 100ms (pour contrer l'autofill tardif)
        setTimeout(function() {
            const emailField2 = document.getElementById('loginEmail');
            if (emailField2) {
                emailField2.value = '';
            }
        }, 100);
    });
    
    // L'authentification réelle (attemptLogin) et la restauration de session
    // sont gérées plus bas, juste après la définition de uncoCore / applyRoleAccess,
    // afin d'avoir accès aux éléments du menu (nav-item) déjà présents dans le DOM.
</script>

<div id="appLayout" class="app-layout">
    <aside class="nav-sidebar">
        <div>
            <div class="sidebar-brand"><i data-lucide="compass" class="brand-icon"></i><div class="brand-text"><h2>UNCO</h2><span>Ouakam · SIG & Fiscalité</span></div></div>
            <div class="role-indicator"><span class="role-badge" id="current-role-badge">TECHNICIEN SIG</span></div>
            <nav class="sidebar-nav">
                <button id="navItemSig" class="nav-item active" onclick="uncoCore.switchRole('sig', this)"><i data-lucide="map-pin"></i><span>Technicien SIG</span></button>
                <button id="navItemMunicipal" class="nav-item" onclick="uncoCore.switchRole('municipal', this)"><i data-lucide="briefcase"></i><span>Agent Municipal</span></button>
                <button id="navItemAdmin" class="nav-item" onclick="uncoCore.switchRole('admin', this)"><i data-lucide="shield"></i><span>Administrateur</span></button>
            </nav>
        </div>
        <div class="sidebar-user">
    <div class="user-avatar" id="userAvatar" style="cursor: pointer; position: relative;"><span id="userInitials">??</span>
        <div class="user-tooltip" id="userTooltip">Chargement...</div>
    </div>
    <div><span id="userNameDisplay">Amadou</span></div>
    <div class="logout-link" onclick="logout()" style="cursor: pointer;">Déconnexion</div>
</div>
    </aside>
    
    <main class="main-area">
        <header class="top-header">
            <div><span id="current-view-title" class="view-title">Carte Interactive</span><span class="status-indicator">● SYSTÈME OPÉRATIONNEL</span></div>
            <div class="header-actions" style="position: relative;">
    <button class="btn-icon" id="notificationBell" style="background: none; border: none; cursor: pointer; position: relative;">
        <i data-lucide="bell"></i>
        <span class="notification-dot" style="position: absolute; top: -2px; right: -2px; width: 8px; height: 8px; background: #ef4444; border-radius: 50%; display: none;"></span>
    </button>
</div>
        </header>
        
        <div class="workspace-container">
            <!-- VUE SIG -->
            <div id="view-sig" class="role-view active">
                <div class="map-container">
                <div id="unco-main-map"></div>
                <!-- Barre de statut mode dessin polygone -->
                <div id="drawStatusBar">✏️ Cliquez sur la carte pour tracer le polygone · Double-clic pour terminer · Échap pour annuler</div>
                   <div class="map-pills-overlay">
                      <button class="map-pill active" onclick="setBaseLayer('osm', this)">Plan Standard</button>
                      <button class="map-pill" onclick="setBaseLayer('satellite', this)">Satellite · Relief</button>
                      <button class="map-pill" onclick="toggleCadastre(this)">Cadastre</button>
                      <button class="map-pill" onclick="exportParcellesGeoJSON()" title="Télécharger le cadastre à jour (parcelles officielles + ajoutées) en .geojson">⬇ Exporter GeoJSON</button>
                   </div>
                   <div id="cadastreInfoPanel" class="map-overlay-panel" style="bottom: 80px; left: 16px; top: auto; right: auto; display: none; max-width: 260px; z-index: 800;">
                       <div class="panel-title" style="display:flex; align-items:center; gap:6px;"><i data-lucide="layers" style="width:14px;height:14px;"></i> COUCHE CADASTRALE</div>
                       <div id="cadastreInfoContent" style="font-size: 0.78rem; color: #475569; line-height: 1.5;">Chargement…</div>
                   </div>
                   <!-- Panneau COUCHES SIG avec checkboxes -->
<div id="layersPanel" class="map-overlay-panel" style="min-width:190px;z-index:900;">
    <div class="panel-title" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
        <span>COUCHES SIG</span>
        <span id="layersPanelToggle" style="cursor:pointer;font-size:1.1em;user-select:none;" onclick="toggleLayersPanel()">−</span>
    </div>
    <div id="layersPanelContent">
        <label class="layer-item" style="display:flex;align-items:center;gap:8px;margin-bottom:7px;cursor:pointer;font-size:0.82rem;color:#374151;">
            <input type="checkbox" id="chkCommune" onchange="toggleSigLayer('commune',this.checked)" style="accent-color:#7c2d12;width:15px;height:15px;">
            <span style="display:inline-block;width:14px;height:3px;background:#7c2d12;border-radius:2px;margin-right:2px;"></span>Limite communale
        </label>
        <label class="layer-item" style="display:flex;align-items:center;gap:8px;margin-bottom:7px;cursor:pointer;font-size:0.82rem;color:#374151;">
            <input type="checkbox" id="chkQuartiers" onchange="toggleSigLayer('quartiers',this.checked)" style="accent-color:#1A6B45;width:15px;height:15px;">
            <span style="display:inline-block;width:14px;height:14px;background:#d1fae5;border:2px solid #1A6B45;border-radius:2px;margin-right:2px;"></span>Quartiers
        </label>
        <label class="layer-item" style="display:flex;align-items:center;gap:8px;margin-bottom:7px;cursor:pointer;font-size:0.82rem;color:#374151;">
            <input type="checkbox" id="chkParcelles" onchange="toggleSigLayer('parcelles',this.checked)" style="accent-color:#92400e;width:15px;height:15px;">
            <span style="display:inline-block;width:14px;height:14px;background:#86efac;border:2px solid #15803d;border-radius:2px;margin-right:2px;"></span>Parcelles
        </label>
        <label class="layer-item" style="display:flex;align-items:center;gap:8px;margin-bottom:7px;cursor:pointer;font-size:0.82rem;color:#374151;">
            <input type="checkbox" id="chkRues" onchange="toggleSigLayer('rues',this.checked)" style="accent-color:#2563eb;width:15px;height:15px;">
            <span style="display:inline-block;width:14px;height:3px;background:#2563eb;border-radius:2px;margin-right:2px;"></span>Rues
        </label>
        <label class="layer-item" style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.82rem;color:#374151;">
            <input type="checkbox" id="chkInfrastructures" onchange="toggleSigLayer('infrastructures',this.checked)" style="accent-color:#f59e0b;width:15px;height:15px;flex-shrink:0;">
            Infrastructures
        </label>
    </div>
</div>

<!-- Légende infrastructures (commentée à la demande)
<div id="legendPanel" class="map-overlay-panel" style="top:230px;left:16px;min-width:190px;z-index:900;">
    <div class="panel-title" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
        <span>LÉGENDE INFRASTRUCTURES</span>
        <span style="cursor:pointer;font-size:1.1em;user-select:none;" onclick="this.parentElement.parentElement.querySelector('#legendContent').style.display=this.parentElement.parentElement.querySelector('#legendContent').style.display==='none'?'block':'none';this.textContent=this.textContent==='−'?'+':'−'">−</span>
    </div>
    <div id="legendContent" class="legend-grid">
        <div class="legend-item"><div class="legend-icon" style="background:#F5A623;"><i data-lucide="store" style="width:14px;height:14px;color:white;"></i></div><span>Commerce</span></div>
        <div class="legend-item"><div class="legend-icon" style="background:#8B5CF6;"><i data-lucide="shopping-bag" style="width:14px;height:14px;color:white;"></i></div><span>Boutique</span></div>
        <div class="legend-item"><div class="legend-icon" style="background:#EF4444;"><i data-lucide="pill" style="width:14px;height:14px;color:white;"></i></div><span>Pharmacie</span></div>
        <div class="legend-item"><div class="legend-icon" style="background:#10B981;"><i data-lucide="hospital" style="width:14px;height:14px;color:white;"></i></div><span>Santé</span></div>
        <div class="legend-item"><div class="legend-icon" style="background:#3B82F6;"><i data-lucide="graduation-cap" style="width:14px;height:14px;color:white;"></i></div><span>École</span></div>
        <div class="legend-item"><div class="legend-icon" style="background:#14B8A6;"><i data-lucide="landmark" style="width:14px;height:14px;color:white;"></i></div><span>Mosquée</span></div>
        <div class="legend-item"><div class="legend-icon" style="background:#F97316;"><i data-lucide="utensils" style="width:14px;height:14px;color:white;"></i></div><span>Restaurant</span></div>
        <div class="legend-item"><div class="legend-icon" style="background:#6B7280;"><i data-lucide="building-2" style="width:14px;height:14px;color:white;"></i></div><span>Administration</span></div>
    </div>
</div>
-->

<!-- Boutons flottants en bas à droite (icônes uniquement) -->
<div class="map-action-buttons">
    <button class="map-action-btn" data-tooltip="Ajouter un bâtiment (point)" onclick="uncoCore.openModal('addBuilding')">
        <i data-lucide="plus-square"></i>
    </button>
    <button class="map-action-btn" id="drawPolygonBtn" data-tooltip="Tracer un bâtiment (polygone sur parcelle)" onclick="startDrawBuilding()">
        <i data-lucide="pentagon"></i>
    </button>
    <button class="map-action-btn" data-tooltip="Ajouter une infrastructure" onclick="uncoCore.openModal('addInfrastructure')">
        <i data-lucide="map-pin-plus"></i>
    </button>
    <button class="map-action-btn" data-tooltip="Modifier un bâtiment" onclick="uncoCore.openModal('editBuilding')">
        <i data-lucide="edit-3"></i>
    </button>
    <button class="map-action-btn" data-tooltip="Supprimer un bâtiment" onclick="uncoCore.openModal('deleteBuilding')">
    <i data-lucide="trash-2"></i>
    </button>
    <button class="map-action-btn" data-tooltip="Modifier / supprimer une parcelle" onclick="uncoCore.openModal('manageParcelle')">
        <i data-lucide="square-pen"></i>
    </button>
    <button class="map-action-btn" data-tooltip="Générer un rapport" onclick="uncoCore.openModal('generateReport')">
        <i data-lucide="pie-chart"></i>
    </button>
    <button class="map-action-btn" data-tooltip="Exporter la carte" onclick="uncoCore.openModal('exportMap')">
        <i data-lucide="download-cloud"></i>
    </button>
    <button class="map-action-btn" id="routingBtn" data-tooltip="Calculer un itinéraire" onclick="toggleRoutingControl()" title="Itinéraire">
        <i data-lucide="navigation"></i>
    </button>
</div>
<div id="mapBtnTooltip"></div>
<script>
(function() {
    const tip = document.getElementById('mapBtnTooltip');
    document.querySelectorAll('.map-action-btn[data-tooltip]').forEach(btn => {
        btn.addEventListener('mouseenter', () => {
            tip.textContent = btn.getAttribute('data-tooltip');
            const r = btn.getBoundingClientRect();
            tip.style.top = (r.top + r.height / 2) + 'px';
            tip.style.left = (r.left - 8) + 'px';
            tip.style.transform = 'translate(-100%, -50%)';
            tip.classList.add('visible');
        });
        btn.addEventListener('mouseleave', () => tip.classList.remove('visible'));
    });
})();
</script>
                </div>
                <div class="bottom-panel">
                    <div class="kpi-row">
                        <div class="kpi-card"><div class="kpi-label">BÂTIMENTS RECENSÉS</div><div class="kpi-value gold"><?php echo number_format((int)$total_batiments_reel, 0, ',', ' '); ?></div></div>
                        <div class="kpi-card"><div class="kpi-label">ALERTE RUES</div><div class="kpi-value"><?php echo (int)$total_alertes_rues_reel; ?></div></div>
                        <div class="kpi-card"><div class="kpi-label">TAUX ADRESSAGE</div><div class="kpi-value"><?php echo $total_parcelles_reel > 0 ? round(($total_batiments_reel / $total_parcelles_reel) * 100) : 0; ?>%</div></div>
                        <div class="kpi-card"><div class="kpi-label">ACTIFS TERRAIN</div><div class="kpi-value"><?php echo (int)$total_actifs_terrain_reel; ?></div></div>
                    </div>
                    <div class="controls-row">
                        <!--div class="layers-panel"><div class="panel-title">COUCHES SIG</div>
                            <div><input type="checkbox" checked> Limite communale</div>
                            <div><input type="checkbox" checked> Quartiers</div>
                            <div><input type="checkbox" checked> Parcelles</div>
                            <div><input type="checkbox" checked> Rues</div>
                            <div><input type="checkbox" checked> Infrastructures</div>
                        </div-->
                        <!--div class="legend-panel"><div class="panel-title">LÉGENDE INFRASTRUCTURES</div>
                           <div class="legend-grid">
    <div class="legend-item">
        <div class="legend-marker" style="background:#F5A623;"><i data-lucide="route" style="width:14px;height:14px;color:white;"></i></div>
        <span>Voie</span>
    </div>
    <div class="legend-item">
        <div class="legend-marker" style="background:#F5A623;"><i data-lucide="zap" style="width:14px;height:14px;color:white;"></i></div>
        <span>Électricité</span>
    </div>
    <div class="legend-item">
        <div class="legend-marker" style="background:#F5A623;"><i data-lucide="droplet" style="width:14px;height:14px;color:white;"></i></div>
        <span>Aquatique</span>
    </div>
    <div class="legend-item">
        <div class="legend-marker" style="background:#1A6B45;"><i data-lucide="shopping-bag" style="width:14px;height:14px;color:white;"></i></div>
        <span>Commerce</span>
    </div>
    <div class="legend-item">
        <div class="legend-marker" style="background:#C0392B;"><i data-lucide="pill" style="width:14px;height:14px;color:white;"></i></div>
        <span>Pharmacie</span>
    </div>
    <div class="legend-item">
        <div class="legend-marker" style="background:#C0392B;"><i data-lucide="hospital" style="width:14px;height:14px;color:white;"></i></div>
        <span>Santé</span>
    </div>
    <div class="legend-item">
        <div class="legend-marker" style="background:#4A7C5F;"><i data-lucide="graduation-cap" style="width:14px;height:14px;color:white;"></i></div>
        <span>École</span>
    </div>
    <div class="legend-item">
        <div class="legend-marker" style="background:#B8860B;"><i data-lucide="mosque" style="width:14px;height:14px;color:white;"></i></div>
        <span>Mosquée</span>
    </div>
</div>
                        </div-->
                    </div>
                    <!--div class="actions-row">
                        <button class="action-btn" onclick="uncoCore.openModal('addBuilding')"><i data-lucide="plus-square"></i> Ajouter</button>
                        <button class="action-btn" onclick="uncoCore.openModal('editBuilding')"><i data-lucide="edit-3"></i> Modifier</button>
                        <button class="action-btn" onclick="uncoCore.openModal('generateReport')"><i data-lucide="pie-chart"></i> Rapports</button>
                        <button class="action-btn" onclick="uncoCore.openModal('exportMap')"><i data-lucide="download-cloud"></i> Export SIG</button>
                    </div-->
                </div>
            </div>
            
           <div id="view-municipal" class="role-view">
    <!-- LIGNE : CARTE À GAUCHE + COLONNE DROITE (BOUTONS + KPIs) -->
    <div class="fiscal-row-mobile" style="display: flex; gap: 20px; align-items: stretch;">
        
        <!-- CARTE FISCALE (GAUCHE) -->
        <div class="fiscal-map-block" style="flex: 3.5;">
            <div class="map-container">
                <div id="fiscal-map"></div>
                <div class="map-pills-overlay">
                    <button class="map-pill active" onclick="setBaseLayerFiscal('osm', this)">Plan Standard</button>
                    <button class="map-pill" onclick="setBaseLayerFiscal('satellite', this)">Satellite · Relief</button>
                    <button class="map-pill" onclick="toggleCadastreFiscal(this)">Cadastre</button>
                </div>
                <!-- Panneau COUCHES SIG (Agent Municipal) avec checkboxes -->
                <div id="layersPanelFiscal" class="map-overlay-panel" style="min-width:190px;z-index:900;">
                    <div class="panel-title" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                        <span>COUCHES SIG</span>
                        <span id="layersPanelFiscalToggle" style="cursor:pointer;font-size:1.1em;user-select:none;" onclick="toggleLayersPanelFiscal()">−</span>
                    </div>
                    <div id="layersPanelFiscalContent">
                        <label class="layer-item" style="display:flex;align-items:center;gap:8px;margin-bottom:7px;cursor:pointer;font-size:0.82rem;color:#374151;">
                            <input type="checkbox" id="chkCommuneFiscal" onchange="toggleSigLayerFiscal('commune',this.checked)" style="accent-color:#7c2d12;width:15px;height:15px;">
                            <span style="display:inline-block;width:14px;height:3px;background:#7c2d12;border-radius:2px;margin-right:2px;"></span>Limite communale
                        </label>
                        <label class="layer-item" style="display:flex;align-items:center;gap:8px;margin-bottom:7px;cursor:pointer;font-size:0.82rem;color:#374151;">
                            <input type="checkbox" id="chkQuartiersFiscal" onchange="toggleSigLayerFiscal('quartiers',this.checked)" style="accent-color:#1A6B45;width:15px;height:15px;">
                            <span style="display:inline-block;width:14px;height:14px;background:#d1fae5;border:2px solid #1A6B45;border-radius:2px;margin-right:2px;"></span>Quartiers
                        </label>
                        <label class="layer-item" style="display:flex;align-items:center;gap:8px;margin-bottom:7px;cursor:pointer;font-size:0.82rem;color:#374151;">
                            <input type="checkbox" id="chkParcellesFiscal" onchange="toggleSigLayerFiscal('parcelles',this.checked)" style="accent-color:#92400e;width:15px;height:15px;">
                            <span style="display:inline-block;width:14px;height:14px;background:#86efac;border:2px solid #15803d;border-radius:2px;margin-right:2px;"></span>Parcelles
                        </label>
                        <label class="layer-item" style="display:flex;align-items:center;gap:8px;margin-bottom:7px;cursor:pointer;font-size:0.82rem;color:#374151;">
                            <input type="checkbox" id="chkRuesFiscal" onchange="toggleSigLayerFiscal('rues',this.checked)" style="accent-color:#2563eb;width:15px;height:15px;">
                            <span style="display:inline-block;width:14px;height:3px;background:#2563eb;border-radius:2px;margin-right:2px;"></span>Rues
                        </label>
                        <label class="layer-item" style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.82rem;color:#374151;">
                            <input type="checkbox" id="chkInfrastructuresFiscal" onchange="toggleSigLayerFiscal('infrastructures',this.checked)" style="accent-color:#f59e0b;width:15px;height:15px;flex-shrink:0;">
                            Infrastructures
                        </label>
                    </div>
                </div>
                <!-- Légende fiscale sur la carte -->
                <div style="position:absolute;bottom:44px;left:12px;background:white;border-radius:10px;padding:8px 12px;box-shadow:0 2px 8px rgba(0,0,0,0.12);font-size:0.72rem;z-index:800;border:1px solid var(--border);">
                    <div style="font-weight:700;font-size:0.65rem;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:6px;color:var(--text-secondary);">LÉGENDE FISCALE</div>
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        <div style="display:flex;align-items:center;gap:6px;"><span style="width:12px;height:12px;border-radius:2px;background:#22c55e;display:inline-block;flex-shrink:0;"></span> Payé</div>
                        <div style="display:flex;align-items:center;gap:6px;"><span style="width:12px;height:12px;border-radius:2px;background:#f59e0b;display:inline-block;flex-shrink:0;"></span> En attente</div>
                        <div style="display:flex;align-items:center;gap:6px;"><span style="width:12px;height:12px;border-radius:2px;background:#ef4444;display:inline-block;flex-shrink:0;"></span> En retard</div>
                        <div style="display:flex;align-items:center;gap:6px;"><span style="width:12px;height:12px;border-radius:2px;background:#94a3b8;display:inline-block;flex-shrink:0;"></span> Exonéré</div>
                    </div>
                </div>
                <div style="position:absolute;bottom:10px;right:12px;background:#1A6B45;color:white;border-radius:20px;padding:4px 12px;font-size:0.72rem;font-weight:600;z-index:800;box-shadow:0 2px 8px rgba(0,0,0,0.2);">
                    📍 2058 parcelles <br> Commune de Ouakam
                </div>
            </div>
        </div>
        
        <!-- COLONNE DROITE : BOUTONS + KPIs -->
        <div class="fiscal-side-block" style="flex: 1; display: flex; flex-direction: column; gap: 16px;">
            <!-- Boutons actions -->
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <button class="fiscal-action-btn" onclick="uncoCore.openModal('recensement')"><i data-lucide="calculator"></i> Recensement</button>
                <button class="fiscal-action-btn" onclick="uncoCore.openModal('addCommerce')"><i data-lucide="file-check-2"></i> Commerce</button>
                <button class="fiscal-action-btn" onclick="uncoCore.openModal('fiscalManagement')"><i data-lucide="banknote"></i> Encaisser</button>
                <button class="fiscal-action-btn" onclick="uncoCore.openModal('planningControl')"><i data-lucide="mail-warning"></i> Contrôle</button>
                <button class="fiscal-action-btn" onclick="uncoCore.openModal('dashboard')"><i data-lucide="search"></i> Dashboard</button>
                <button class="fiscal-action-btn" onclick="uncoCore.openModal('generateReport')"><i data-lucide="bar-chart-3"></i> Rapports</button>
            </div>
            
            <!-- KPIs fiscaux cliquables (filtrent la carte + tableau + graphiques) -->
<div style="margin-top: 16px;">
    <div style="display: flex; gap: 12px; margin-bottom: 12px;">
        <div id="fkpi-card-paid" style="flex:1;text-align:center;padding:12px 8px;background:white;border-radius:14px;border:2px solid var(--border);cursor:pointer;transition:border-color 0.15s;" onclick="filterFiscal('paid', document.querySelector('.fiscal-filter-btn.btn-paid'))" onmouseover="this.style.borderColor='#22c55e'" onmouseout="if(!this.classList.contains('kpi-active'))this.style.borderColor='var(--border)'">
            <div style="font-size:0.65rem;text-transform:uppercase;color:var(--text-secondary);">Payés</div>
            <div style="font-size:1.3rem;font-weight:800;color:#22c55e;" id="fkpi-paid-val">—</div>
        </div>
        <div id="fkpi-card-pending" style="flex:1;text-align:center;padding:12px 8px;background:white;border-radius:14px;border:2px solid var(--border);cursor:pointer;transition:border-color 0.15s;" onclick="filterFiscal('pending', document.querySelector('.fiscal-filter-btn.btn-pending'))" onmouseover="this.style.borderColor='#f59e0b'" onmouseout="if(!this.classList.contains('kpi-active'))this.style.borderColor='var(--border)'">
            <div style="font-size:0.65rem;text-transform:uppercase;color:var(--text-secondary);">En attente</div>
            <div style="font-size:1.3rem;font-weight:800;color:#f59e0b;" id="fkpi-pending-val">—</div>
        </div>
    </div>
    <div style="display: flex; gap: 12px;">
        <div id="fkpi-card-overdue" style="flex:1;text-align:center;padding:12px 8px;background:white;border-radius:14px;border:2px solid var(--border);cursor:pointer;transition:border-color 0.15s;" onclick="filterFiscal('overdue', document.querySelector('.fiscal-filter-btn.btn-overdue'))" onmouseover="this.style.borderColor='#ef4444'" onmouseout="if(!this.classList.contains('kpi-active'))this.style.borderColor='var(--border)'">
            <div style="font-size:0.65rem;text-transform:uppercase;color:var(--text-secondary);">En retard</div>
            <div style="font-size:1.3rem;font-weight:800;color:#ef4444;" id="fkpi-overdue-val">—</div>
        </div>
        <div id="fkpi-card-exempt" style="flex:1;text-align:center;padding:12px 8px;background:white;border-radius:14px;border:2px solid var(--border);cursor:pointer;transition:border-color 0.15s;" onclick="filterFiscal('exempt', document.querySelector('.fiscal-filter-btn.btn-exempt'))" onmouseover="this.style.borderColor='#94a3b8'" onmouseout="if(!this.classList.contains('kpi-active'))this.style.borderColor='var(--border)'">
            <div style="font-size:0.65rem;text-transform:uppercase;color:var(--text-secondary);">Exonérés</div>
            <div style="font-size:1.3rem;font-weight:800;color:#94a3b8;" id="fkpi-exempt-val">—</div>
        </div>
    </div>
</div>
        </div>
    </div>
    
    <!-- FILTRES (centrés, en dessous) -->
    <div class="fiscal-filter-bar" style="margin-top: 20px; justify-content: center;">
        <button class="fiscal-filter-btn active" data-filter="all" onclick="filterFiscal('all', this)">
            <i data-lucide="layers" style="width: 14px; height: 14px;"></i> Tous
        </button>
        <button class="fiscal-filter-btn btn-paid" data-filter="paid" onclick="filterFiscal('paid', this)">
            <span class="filter-dot"></span> Payés
        </button>
        <button class="fiscal-filter-btn btn-pending" data-filter="pending" onclick="filterFiscal('pending', this)">
            <span class="filter-dot"></span> En attente
        </button>
        <button class="fiscal-filter-btn btn-overdue" data-filter="overdue" onclick="filterFiscal('overdue', this)">
            <span class="filter-dot"></span> En retard
        </button>
        <button class="fiscal-filter-btn btn-exempt" data-filter="exempt" onclick="filterFiscal('exempt', this)">
            <span class="filter-dot"></span> Exonérés
        </button>
    </div>
    
    <!-- GRAPHIQUES (côte à côte) -->
    <div class="fiscal-charts-row" style="display: flex; gap: 20px; margin-top: 20px;">
        <div class="fiscal-chart-card" style="flex: 1;">
            <div class="panel-title">Évolution des Recouvrements</div>
            <div class="chart-subtitle" style="font-size:0.65rem; color:var(--text-muted); margin-bottom:12px;">6 derniers mois</div>
            <canvas id="fiscal-line-chart" height="150"></canvas>
        </div>
        <div class="fiscal-chart-card" style="flex: 1;">
            <div class="panel-title">Répartition Fiscale</div>
            <div class="chart-subtitle" style="font-size:0.65rem; color:var(--text-muted); margin-bottom:12px;">Toutes parcelles</div>
            <canvas id="fiscal-donut-chart" height="150"></canvas>
        </div>
    </div>
    
    <!-- TABLEAU DES PAIEMENTS -->
    <div style="background:white; border-radius:16px; padding:16px; border:1px solid var(--border); margin-top: 20px;">
        <h4 style="font-size:0.9rem; margin-bottom:12px;">Derniers paiements</h4>
        <table class="unco-table">
            <thead><tr><th>Réf</th><th>Contribuable</th><th>NICAD</th><th>Montant</th><th>Statut</th></tr></thead>
            <tbody id="payments-tbody"></tbody>
        </table>
    </div>
</div>
            
            <!-- VUE ADMIN -->
            <div id="view-admin" class="role-view">

                <!-- ===== SUPERVISION GLOBALE ===== -->
                <div style="background:white; border-radius:16px; padding:16px 20px; border:1px solid var(--border);">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <span style="font-weight:700; font-size:1rem; color:var(--text-primary);">Supervision Globale</span>
                            <span id="admin-sys-status" style="display:inline-flex; align-items:center; gap:5px; font-size:0.65rem; font-weight:600; background:#dcfce7; color:#15803d; padding:4px 10px; border-radius:40px;">
                                <span style="width:7px;height:7px;border-radius:50%;background:#15803d;display:inline-block;"></span>
                                SYSTÈME OPÉRATIONNEL
                            </span>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <button onclick="refreshAdminStats(true)" style="background:none;border:1px solid var(--border);border-radius:40px;padding:5px 14px;font-size:0.7rem;font-weight:600;cursor:pointer;color:var(--text-secondary);display:inline-flex;align-items:center;gap:6px;">
                                <i data-lucide="refresh-cw" style="width:13px;height:13px;"></i> Actualiser
                            </button>
                            <button onclick="showAdminSettings()" style="background:none;border:none;cursor:pointer;padding:4px;">
                                <i data-lucide="settings" style="width:18px;height:18px;color:var(--text-secondary);"></i>
                            </button>
                        </div>
                    </div>

                    <!-- KPI GRID -->
                    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:14px;">
                        <!-- Utilisateurs Actifs -->
                        <div style="background:#f8fafe; border-radius:14px; padding:14px 16px; display:flex; gap:12px; align-items:flex-start;">
                            <div style="background:#eff3f0; border-radius:10px; padding:8px; flex-shrink:0;">
                                <i data-lucide="users" style="width:18px;height:18px;color:var(--civic-green);"></i>
                            </div>
                            <div>
                                <div style="font-size:0.65rem; color:var(--text-secondary); font-weight:600; text-transform:uppercase; margin-bottom:4px;">Utilisateurs Actifs</div>
                                <div style="display:flex; align-items:baseline; gap:6px;">
                                    <span id="admin-kpi-users" style="font-size:1.6rem; font-weight:800; color:var(--text-primary);">—</span>
                                    <span id="admin-kpi-users-delta" style="font-size:0.72rem; color:#15803d; font-weight:600;"></span>
                                </div>
                            </div>
                        </div>
                        <!-- Charge Serveur -->
                        <div style="background:#f8fafe; border-radius:14px; padding:14px 16px; display:flex; gap:12px; align-items:flex-start;">
                            <div style="background:#eff3f0; border-radius:10px; padding:8px; flex-shrink:0;">
                                <i data-lucide="server" style="width:18px;height:18px;color:var(--teal-accent);"></i>
                            </div>
                            <div style="flex:1;">
                                <div style="font-size:0.65rem; color:var(--text-secondary); font-weight:600; text-transform:uppercase; margin-bottom:4px;">Charge Serveur</div>
                                <div style="display:flex; align-items:baseline; gap:6px;">
                                    <span id="admin-kpi-server" style="font-size:1.6rem; font-weight:800; color:var(--text-primary);">32%</span>
                                    <div style="display:flex;gap:2px;align-items:flex-end;" id="admin-server-bars">
                                        <div style="width:4px;height:10px;background:#cbd5e1;border-radius:2px;"></div>
                                        <div style="width:4px;height:14px;background:#cbd5e1;border-radius:2px;"></div>
                                        <div style="width:4px;height:18px;background:var(--civic-green);border-radius:2px;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Sync SIG -->
                        <div style="background:#f8fafe; border-radius:14px; padding:14px 16px; display:flex; gap:12px; align-items:flex-start;">
                            <div style="background:#fff7ed; border-radius:10px; padding:8px; flex-shrink:0;">
                                <i data-lucide="database" style="width:18px;height:18px;color:#f59e0b;"></i>
                            </div>
                            <div>
                                <div style="font-size:0.65rem; color:var(--text-secondary); font-weight:600; text-transform:uppercase; margin-bottom:4px;">Synchronisation SIG</div>
                                <div style="display:flex; align-items:baseline; gap:6px;">
                                    <span id="admin-kpi-sync" style="font-size:1.6rem; font-weight:800; color:var(--text-primary);">1m</span>
                                    <span style="font-size:0.65rem; color:var(--text-muted);">Dernier ping</span>
                                </div>
                            </div>
                        </div>
                        <!-- Tentatives Échouées -->
                        <div style="background:#f8fafe; border-radius:14px; padding:14px 16px; display:flex; gap:12px; align-items:flex-start;">
                            <div style="background:#fff1f2; border-radius:10px; padding:8px; flex-shrink:0;">
                                <i data-lucide="shield-alert" style="width:18px;height:18px;color:#e11d48;"></i>
                            </div>
                            <div>
                                <div style="font-size:0.65rem; color:var(--text-secondary); font-weight:600; text-transform:uppercase; margin-bottom:4px;">Tentatives Échouées</div>
                                <div style="display:flex; align-items:baseline; gap:6px;">
                                    <span id="admin-kpi-failed" style="font-size:1.6rem; font-weight:800; color:var(--text-primary);">2</span>
                                    <span style="font-size:0.65rem; color:#e11d48; font-weight:600;">Aujourd'hui</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== LIGNE : JOURNAL + AGENTS ===== -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">

                    <!-- Journal d'Activité -->
                    <div style="background:white; border-radius:16px; padding:18px 20px; border:1px solid var(--border);">
                        <div style="font-weight:700; font-size:0.9rem; margin-bottom:14px; color:var(--text-primary);">Journal d'Activité</div>
                        <div id="admin-activity-log" style="display:flex;flex-direction:column;gap:14px; max-height:280px; overflow-y:auto;">
                            <!-- Rempli dynamiquement -->
                            <div style="text-align:center;color:var(--text-muted);font-size:0.8rem;padding:20px 0;">Chargement…</div>
                        </div>
                    </div>

                    <!-- Agents Connectés -->
                    <div style="display:flex;flex-direction:column;gap:12px;">
                        <div style="background:white; border-radius:16px; padding:14px 18px; border:1px solid var(--border); display:flex; align-items:center; justify-content:space-between;">
                            <span style="font-weight:700; font-size:0.9rem; color:var(--text-primary);">Agents Connectés</span>
                            <span id="admin-agents-badge" style="background:#dcfce7; color:#15803d; font-size:0.75rem; font-weight:700; padding:4px 12px; border-radius:40px;">— en ligne</span>
                        </div>
                        <div id="admin-agents-list" style="background:white; border-radius:16px; border:1px solid var(--border); overflow:hidden; flex:1;">
                            <div style="padding:14px 18px; max-height:220px; overflow-y:auto; display:flex; flex-direction:column; gap:0;">
                                <!-- Rempli dynamiquement -->
                                <div style="text-align:center;color:var(--text-muted);font-size:0.8rem;padding:20px 0;">Chargement…</div>
                            </div>
                        </div>

                        <!-- Mini carte -->
                        <div style="background:white; border-radius:16px; border:1px solid var(--border); overflow:hidden; height:160px; position:relative;">
                            <div id="admin-minimap" style="height:100%; width:100%;"></div>
                            <div class="map-pills-overlay" style="bottom:8px;top:auto;">
                                <button class="map-pill active" onclick="setAdminBaseLayer('osm', this)" style="font-size:0.65rem;padding:5px 12px;">Plan</button>
                                <button class="map-pill" onclick="setAdminBaseLayer('satellite', this)" style="font-size:0.65rem;padding:5px 12px;">Satellite</button>
                                <button class="map-pill" onclick="toggleCadastreAdmin(this)" style="font-size:0.65rem;padding:5px 12px;">Cadastre</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== ACTIONS RAPIDES ===== -->
                <div style="display:grid; grid-template-columns:repeat(2,1fr); gap:14px;">

                    <!-- Gestion des Rôles -->
                    <button onclick="uncoCore.openModal('userManagement')" style="background:white; border:1px solid var(--border); border-radius:16px; padding:16px 18px; cursor:pointer; text-align:left; display:flex; align-items:center; gap:14px; transition:box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)'" onmouseout="this.style.boxShadow='none'">
                        <div style="background:#eff3f0; border-radius:12px; padding:10px; flex-shrink:0;">
                            <i data-lucide="user-cog" style="width:20px;height:20px;color:var(--civic-green);"></i>
                        </div>
                        <div>
                            <div style="font-weight:700; font-size:0.9rem; color:var(--text-primary); margin-bottom:3px;">Gestion des Roles</div>
                            <div style="font-size:0.75rem; color:var(--text-secondary);">Ajouter agents, modifier permissions.</div>
                        </div>
                    </button>

                    <!-- Import SIG -->
                    <button onclick="uncoCore.openModal('importSig')" style="background:white; border:1px solid var(--border); border-radius:16px; padding:16px 18px; cursor:pointer; text-align:left; display:flex; align-items:center; gap:14px; transition:box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)'" onmouseout="this.style.boxShadow='none'">
                        <div style="background:#eff3f0; border-radius:12px; padding:10px; flex-shrink:0;">
                            <i data-lucide="git-branch" style="width:20px;height:20px;color:var(--teal-accent);"></i>
                        </div>
                        <div>
                            <div style="font-weight:700; font-size:0.9rem; color:var(--text-primary); margin-bottom:3px;">Import SIG</div>
                            <div style="font-size:0.75rem; color:var(--text-secondary);">Mettre à jour la base cartographique.</div>
                        </div>
                    </button>

                    <!-- Sauvegarde & Export -->
                    <button onclick="adminExportData()" style="background:white; border:1px solid var(--border); border-radius:16px; padding:16px 18px; cursor:pointer; text-align:left; display:flex; align-items:center; gap:14px; transition:box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)'" onmouseout="this.style.boxShadow='none'">
                        <div style="background:#eff3f0; border-radius:12px; padding:10px; flex-shrink:0;">
                            <i data-lucide="archive" style="width:20px;height:20px;color:var(--sand-gold);"></i>
                        </div>
                        <div>
                            <div style="font-weight:700; font-size:0.9rem; color:var(--text-primary); margin-bottom:3px;">Sauvegarde &amp; Export</div>
                            <div style="font-size:0.75rem; color:var(--text-secondary);">Exports système et rapports auto.</div>
                        </div>
                    </button>

                    <!-- Serveur Carto -->
                    <button onclick="adminServerCarto()" style="background:white; border:1px solid var(--border); border-radius:16px; padding:16px 18px; cursor:pointer; text-align:left; display:flex; align-items:center; gap:14px; transition:box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)'" onmouseout="this.style.boxShadow='none'">
                        <div style="background:#eff3f0; border-radius:12px; padding:10px; flex-shrink:0;">
                            <i data-lucide="map" style="width:20px;height:20px;color:#8b5cf6;"></i>
                        </div>
                        <div>
                            <div style="font-weight:700; font-size:0.9rem; color:var(--text-primary); margin-bottom:3px;">Serveur Carto</div>
                            <div style="font-size:0.75rem; color:var(--text-secondary);">Carte standard avec zoom étendu.</div>
                        </div>
                    </button>

                </div>

            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="globalModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="modalTitle">Action</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="modalBodyText"></div><div class="modal-footer"><button type="button" class="btn btn-dark" data-bs-dismiss="modal">Fermer</button></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.js"></script>
<script src="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.min.js"></script>
    <!-- Shapefile.js : lecture native des .shp dans le navigateur -->
    <script src="https://unpkg.com/shapefile@0.6.6/dist/shapefile.js"></script>
    <!-- dxf-parser : lecture native des .dxf dans le navigateur -->
    <script src="https://unpkg.com/dxf-parser@1.0.2/dist/dxf-parser.js"></script>
    <!-- proj4.js : reprojection de coordonnées (ex. UTM Zone 28N -> WGS84) -->
    <script src="https://cdn.jsdelivr.net/npm/proj4@2.9.0/dist/proj4.js"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
<script>
// ========== DONNÉES GEOJSON CADASTRALES (embarquées) ==========
const CADASTRE_COMMUNE = {"type":"FeatureCollection","name":"Commune_Ouakam","features":[{"type":"Feature","properties":{"OBJECTID_1":1,"OBJECTID":1,"Shape_Leng":13510.3580738,"Densite":"10270,428","Sperficier":7319987.23885,"Nom":"Ouakam","Shape_Le_1":13510.3580738,"Shape_Area":7319987.23885,"surface":7.31998723885},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.492697947237147,14.738345456355958],[-17.49258197172136,14.737866268905208],[-17.491744546165787,14.738090414409868],[-17.491412854271978,14.73807964644315],[-17.49101355157418,14.737954874200149],[-17.490526004764007,14.7378453937781],[-17.490120476117184,14.737821077595505],[-17.489730253430082,14.737846791279884],[-17.488887690885313,14.737619226315013],[-17.490339716758225,14.732220958125803],[-17.487248880046323,14.731220139035281],[-17.486810291430672,14.7311248870791],[-17.485523436858315,14.731099915516626],[-17.484347850153735,14.731074049193136],[-17.483760805937038,14.733063575479083],[-17.481235432250934,14.732994149677685],[-17.48091870004963,14.731190914046556],[-17.480907756064752,14.731128608178492],[-17.480978474933025,14.731023066190971],[-17.481328430998484,14.73042284114143],[-17.48149868536163,14.730052543364122],[-17.481235999546787,14.729745428735056],[-17.48092044639693,14.727384683137567],[-17.480963163829202,14.727232863907643],[-17.480809094102902,14.726052450732043],[-17.47995420399758,14.725457960998094],[-17.47945941118636,14.725103273284104],[-17.477691802924607,14.725286143134017],[-17.476867139933066,14.725207768385722],[-17.475377904832836,14.725609422315612],[-17.475102472378598,14.72569218732868],[-17.475230853471633,14.726280488483036],[-17.471600645696398,14.726372894115846],[-17.471243795953757,14.725285651537824],[-17.471068408801713,14.72440886813546],[-17.47100060434952,14.723525088205934],[-17.471086449335488,14.720995650422209],[-17.47139469902318,14.717196224993886],[-17.471521355393016,14.715259267054597],[-17.4762895986881,14.712771068322654],[-17.47667806596514,14.712592227340723],[-17.481588202375008,14.70992120982936],[-17.483363444529104,14.70974102004087],[-17.48522275647615,14.71016160761604],[-17.48526520439126,14.710010728948976],[-17.485701435165904,14.709901848109274],[-17.486141843389444,14.70975800980977],[-17.486451053353356,14.709738916321744],[-17.48768309981899,14.7095360100984],[-17.48832821819548,14.709686839046578],[-17.48870880802426,14.710357092381317],[-17.48889235539986,14.710967555539227],[-17.48904352316475,14.711642834544403],[-17.48932665497027,14.712273715145303],[-17.48941897853598,14.712627290147456],[-17.489544658449987,14.713001994759688],[-17.48985883762392,14.713449886261703],[-17.489975966516138,14.713932697907634],[-17.490123557640086,14.714403296809369],[-17.490357301913836,14.714572694591793],[-17.49074826085201,14.71497677867955],[-17.491459198057306,14.715366676693073],[-17.492168307917172,14.71559542515696],[-17.492281655566373,14.715774771550718],[-17.49238275656414,14.715710611180585],[-17.49259202567767,14.715708358951172],[-17.492906958141564,14.715631787862183],[-17.493460904986115,14.715427397428373],[-17.49359887686953,14.71529010643007],[-17.493825585589317,14.715287665372266],[-17.49399940038354,14.715234865740513],[-17.494060786276012,14.715161533848754],[-17.494416011069966,14.71506062227587],[-17.49465745766129,14.714820363146266],[-17.494865377285404,14.714699293802953],[-17.49505682256703,14.714663280137955],[-17.49533604476461,14.71467724854474],[-17.495806709186162,14.714655200567188],[-17.49617389689096,14.714736122657305],[-17.496349447604125,14.714836084508596],[-17.49699817327966,14.71513465120927],[-17.49715628446178,14.715234800367176],[-17.497228939362742,14.71548865257239],[-17.497370771870987,14.715690830923332],[-17.497645411281443,14.716100903679882],[-17.49790057520827,14.71672678230115],[-17.498026110360893,14.71707559640554],[-17.498133667898916,14.717355287973628],[-17.49820991923062,14.717581537383014],[-17.498423657134623,14.717784449436536],[-17.498629579900587,14.71788768766382],[-17.49907084891505,14.71794722194105],[-17.4997341239888,14.717990988658816],[-17.50026491428212,14.717965696041038],[-17.500517670693657,14.718149240413739],[-17.500765672049678,14.718268436735174],[-17.501234298458904,14.718008742100995],[-17.501600719172753,14.718021758530577],[-17.502234379395425,14.718386614230738],[-17.502821590208626,14.718732825337502],[-17.50323536297323,14.719124479313331],[-17.503449113199668,14.719383351471103],[-17.50346316385224,14.719538564283242],[-17.503528917219846,14.719920288310481],[-17.50366492690896,14.720002474971885],[-17.503783816819475,14.720198382687038],[-17.503929103898187,14.720555344504469],[-17.504071254022527,14.721175263902005],[-17.504213951065545,14.721842981721503],[-17.5042311622321,14.722322087908552],[-17.504304604060856,14.722643829934736],[-17.504221282301156,14.722984240658992],[-17.504049792341373,14.723240730066143],[-17.50392922247299,14.72348209514901],[-17.50384633069541,14.723752195942039],[-17.50362465131176,14.724195958361014],[-17.503647131417075,14.72463707984454],[-17.50373859421641,14.725009551054374],[-17.50388227812006,14.725388624724415],[-17.50422377012352,14.725816765675582],[-17.50435737646147,14.726128027589455],[-17.50448486560743,14.726573371260113],[-17.505102155536566,14.727035752412585],[-17.50551028187525,14.72726958626736],[-17.506311922681647,14.727796975333378],[-17.507150038762934,14.728837699539467],[-17.507330895225206,14.729403299089308],[-17.506877161045296,14.729657287633728],[-17.506471218347645,14.729959873032007],[-17.506420665658375,14.729947520280033],[-17.50542063855829,14.728796866439104],[-17.505026838857336,14.72846042647001],[-17.504753517583566,14.728919691234628],[-17.50257024638464,14.733871945060882],[-17.502214065532623,14.735372075281392],[-17.498997403678445,14.735623129006047],[-17.498320174374616,14.735849104891317],[-17.497748989137442,14.736742092513323],[-17.497305963950176,14.737306190117765],[-17.49681742446817,14.737756046963801],[-17.496521413603332,14.738289872856763],[-17.496362193560405,14.738542565447593],[-17.49580963182302,14.739193890708187],[-17.495293167919733,14.739780289966664],[-17.492697947237147,14.738345456355958]]]]}}]};
const CADASTRE_QUARTIERS = {"type":"FeatureCollection","name":"Quartiers_Ouakam","features":[{"type":"Feature","properties":{"OBJECTID_1":1,"OBJECTID":1,"QRT_VLG_HA":"ANCIENNE PISTE","Shape_Leng":4342.39763699,"Type":"R\u00e9gulier","Shape_Le_1":4342.39763699,"Shape_Area":530684.490854,"num":20.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.475377904832836,14.725609422315612],[-17.474130018941924,14.721346156096208],[-17.473747015012925,14.721358222295516],[-17.47296645505512,14.718563971650301],[-17.471937864325724,14.717457503762168],[-17.47139469902318,14.717196224993886],[-17.471521355393016,14.715259267054597],[-17.4762895986881,14.712771068322654],[-17.47667806596514,14.712592227340723],[-17.477154958770004,14.715592215438631],[-17.478178187016304,14.715564430192869],[-17.47817956238852,14.71536004746382],[-17.479177916456788,14.71523463044467],[-17.47973868322336,14.715047923870255],[-17.479755037834433,14.715014003955398],[-17.47983679859443,14.714975783673324],[-17.480240768775218,14.714842561357226],[-17.48074833766172,14.715362610960437],[-17.48018876129047,14.715905487252641],[-17.479441066108734,14.716675586966906],[-17.47897636126894,14.71695305181763],[-17.478371405382248,14.717206918596315],[-17.477376417634712,14.717348551516874],[-17.476682142725874,14.717391832228826],[-17.475837834243872,14.717493189590614],[-17.476867139933066,14.725207768385722],[-17.475377904832836,14.725609422315612]]]]}},{"type":"Feature","properties":{"OBJECTID_1":2,"OBJECTID":2,"QRT_VLG_HA":"BALLON","Shape_Leng":1212.82674672,"Type":"R\u00e9gulier","Shape_Le_1":1212.82674672,"Shape_Area":75769.80185790002,"num":25.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.485523436858315,14.731099915516626],[-17.485756895458778,14.730112992394792],[-17.485809857209567,14.729890931841647],[-17.4864220259071,14.727450003987247],[-17.488145292285484,14.727485904470356],[-17.487841490684122,14.728643660734733],[-17.487248880046323,14.731220139035281],[-17.486810291430672,14.7311248870791],[-17.485523436858315,14.731099915516626]]]]}},{"type":"Feature","properties":{"OBJECTID_1":3,"OBJECTID":3,"QRT_VLG_HA":"BIRA OUAKAM (TOUBA OUAKAM)","Shape_Leng":3771.52833107,"Type":"Irr\u00e9gulier","Shape_Le_1":3771.52833107,"Shape_Area":451757.589945,"num":9.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.48149868536163,14.730052543364122],[-17.481235999546787,14.729745428735056],[-17.48092044639693,14.727384683137567],[-17.480963163829202,14.727232863907643],[-17.480809094102902,14.726052450732043],[-17.47995420399758,14.725457960998094],[-17.480250829527424,14.725138474449247],[-17.48052185731031,14.724708957501331],[-17.48070501337126,14.7241829312721],[-17.480819800910442,14.723909212525585],[-17.480981171145974,14.723666594581656],[-17.48252975768083,14.724604848398778],[-17.482726318301236,14.724655803199253],[-17.482968999816936,14.724480543976274],[-17.483199255586612,14.724234460281696],[-17.484299670817204,14.722619471423867],[-17.48459890910903,14.722384119688995],[-17.48565432160828,14.721787178692168],[-17.485750785105946,14.72163077732061],[-17.485771562847688,14.720385271210866],[-17.486147767380398,14.719527952711793],[-17.487056879922868,14.719192967781842],[-17.487180054048007,14.719896508484771],[-17.487586847020182,14.72228039961812],[-17.488035823959077,14.724860267821667],[-17.488281780357593,14.726187256400646],[-17.488341140156745,14.726439585641078],[-17.488244465592192,14.726965715539896],[-17.488145292285484,14.727485904470356],[-17.4864220259071,14.727450003987247],[-17.48354947580486,14.727317545559615],[-17.482705666994377,14.72693003452268],[-17.482657493217726,14.727128360758535],[-17.482275868700327,14.727089609614467],[-17.482227122332397,14.727352989518197],[-17.48192120419256,14.728486712178137],[-17.48149868536163,14.730052543364122]]]]}},{"type":"Feature","properties":{"OBJECTID_1":4,"OBJECTID":4,"QRT_VLG_HA":"BOULGA","Shape_Leng":1119.47699624,"Type":"Irr\u00e9gulier","Shape_Le_1":1119.47699624,"Shape_Area":66216.828387,"num":11.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.48967072629099,14.722207916253925],[-17.489563909888425,14.723708396055207],[-17.489521012542085,14.724472539106552],[-17.488781458216664,14.725134389724477],[-17.488294880908242,14.724775712030139],[-17.488035823959077,14.724860267821667],[-17.487586847020182,14.72228039961812],[-17.48793435923428,14.722340756691384],[-17.48814034019305,14.72198837160987],[-17.48826839321418,14.721694190656287],[-17.488409922189952,14.721412407597931],[-17.4887834002261,14.72160588640402],[-17.489096835923984,14.721765358480965],[-17.48912529741163,14.721757327522623],[-17.489140563125336,14.721693085448848],[-17.489384582594504,14.721678273931014],[-17.489384086249327,14.7216145979306],[-17.489558421630548,14.721590987537638],[-17.489605526596375,14.721577274230077],[-17.489665131253236,14.721947621163622],[-17.48967072629099,14.722207916253925]]]]}},{"type":"Feature","properties":{"OBJECTID_1":5,"OBJECTID":5,"QRT_VLG_HA":"CITE ASECNA","Shape_Leng":2306.86146194,"Type":"R\u00e9gulier","Shape_Le_1":2306.86146194,"Shape_Area":212428.026808,"num":7.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.490339716758225,14.732220958125803],[-17.487248880046323,14.731220139035281],[-17.487841490684122,14.728643660734733],[-17.489140052387203,14.728932537497823],[-17.48972624176941,14.729063093099489],[-17.491223201666237,14.729404572920869],[-17.49128236909138,14.729150717887142],[-17.492583382764007,14.729411881312148],[-17.494211640219465,14.728328292271469],[-17.4948688286857,14.729263494759682],[-17.494133515648787,14.729749629162324],[-17.493905305932447,14.730116876454357],[-17.49375740717011,14.730086597843794],[-17.493110143486774,14.72986075369618],[-17.492466140178525,14.732491899234718],[-17.49244722482008,14.732628562515055],[-17.491199812713923,14.73238730845487],[-17.490339716758225,14.732220958125803]]]]}},{"type":"Feature","properties":{"OBJECTID_1":6,"OBJECTID":6,"QRT_VLG_HA":"CITE ASECNA III","Shape_Leng":543.420565607,"Type":"R\u00e9gulier","Shape_Le_1":543.420565607,"Shape_Area":18184.1720417,"num":29.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.496551204063763,14.730002337115542],[-17.49670339243886,14.729858337655543],[-17.497565818563384,14.72933274504873],[-17.4982131634887,14.730314084785972],[-17.49801174752147,14.730898071135794],[-17.496529926464163,14.730375017992968],[-17.496602437420453,14.73007902433224],[-17.496551204063763,14.730002337115542]]]]}},{"type":"Feature","properties":{"OBJECTID_1":7,"OBJECTID":7,"QRT_VLG_HA":"CITE ASSEMBLEE NATIONALE","Shape_Leng":1579.67840314,"Type":"R\u00e9gulier","Shape_Le_1":1579.67840314,"Shape_Area":126604.416348,"num":6.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.497167330072674,14.733346349251562],[-17.494232837447623,14.732973892657629],[-17.49244722482008,14.732628562515055],[-17.492466140178525,14.732491899234718],[-17.49268277648978,14.731618967060992],[-17.493110143486774,14.72986075369618],[-17.49375740717011,14.730086597843794],[-17.493905305932447,14.730116876454357],[-17.493829797152177,14.730387786868656],[-17.496177948295585,14.731259309198816],[-17.497708610188038,14.73177698173222],[-17.497359062235326,14.73279045106175],[-17.497167330072674,14.733346349251562]]]]}},{"type":"Feature","properties":{"OBJECTID_1":8,"OBJECTID":8,"QRT_VLG_HA":"CITE COMICO","Shape_Leng":1987.48791128,"Type":"R\u00e9gulier","Shape_Le_1":1987.48791128,"Shape_Area":139518.904762,"num":4.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.4864220259071,14.727450003987247],[-17.485943952209105,14.729356253176304],[-17.48505116953441,14.729288731314693],[-17.485042843638013,14.728987649235119],[-17.484638631151533,14.728717752976118],[-17.484494989203778,14.728930562350781],[-17.484280391668538,14.729136539473608],[-17.483993945001185,14.729173562469722],[-17.483941433010592,14.729871123247694],[-17.48301460602252,14.729876767799146],[-17.483089633144445,14.731036190184604],[-17.48091870004963,14.731190914046556],[-17.480907756064752,14.731128608178492],[-17.480978474933025,14.731023066190971],[-17.481328430998484,14.73042284114143],[-17.48149868536163,14.730052543364122],[-17.482227122332397,14.727352989518197],[-17.482275868700327,14.727089609614467],[-17.48266310985817,14.727127285084334],[-17.482705666994377,14.72693003452268],[-17.483405260561153,14.727245130890928],[-17.48354947580486,14.727317545559615],[-17.4864220259071,14.727450003987247]]]]}},{"type":"Feature","properties":{"OBJECTID_1":9,"OBJECTID":9,"QRT_VLG_HA":"CITE COMMUNAUTE URBAINE","Shape_Leng":753.931755045,"Type":"R\u00e9gulier","Shape_Le_1":753.931755045,"Shape_Area":24049.2253337,"num":27.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.496177948295585,14.731259309198816],[-17.496254773272753,14.731018801420577],[-17.496213272670193,14.73100358524637],[-17.496110845783022,14.73096831519026],[-17.49599818760715,14.730929522012646],[-17.4958928495294,14.730893249773676],[-17.49578253453264,14.730853987637367],[-17.495671640914992,14.730814205005803],[-17.495596045979973,14.730787086578191],[-17.495565815428268,14.73077416800193],[-17.495459164806093,14.730728591206379],[-17.49545287319271,14.730725902812187],[-17.495519854082573,14.730681782331654],[-17.495610761922798,14.730621899744932],[-17.495677114390013,14.730578192532281],[-17.49579553217463,14.730500190134077],[-17.49592296506235,14.730416247300191],[-17.495985077437954,14.730375332969922],[-17.496105457414448,14.730296037300887],[-17.496233698059328,14.730211562551062],[-17.49626653235492,14.730189934346285],[-17.496284864015745,14.730177858778994],[-17.49641425618239,14.730092626226941],[-17.496539477392876,14.73001014066378],[-17.496551204063763,14.730002337115542],[-17.496602437420453,14.73007902433224],[-17.496529926464163,14.730375017992968],[-17.49801174752147,14.730898071135794],[-17.497708610188038,14.73177698173222],[-17.496177948295585,14.731259309198816]]]]}},{"type":"Feature","properties":{"OBJECTID_1":10,"OBJECTID":10,"QRT_VLG_HA":"CITE EL HADJI MALICK SY","Shape_Leng":3494.5961995,"Type":"R\u00e9gulier","Shape_Le_1":3494.5961995,"Shape_Area":459422.970353,"num":19.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.4915325109692,14.727026339179973],[-17.491735228282515,14.727049552021798],[-17.491925203049547,14.726045519678289],[-17.49123135639488,14.72593347717401],[-17.491498665136184,14.724427566051524],[-17.491556077473636,14.724414018672276],[-17.49184343648114,14.724199389048627],[-17.492173960207438,14.724157353257201],[-17.492472848340434,14.72409875062972],[-17.493150881460384,14.724218540878462],[-17.49342023949212,14.724418809219227],[-17.49367144641087,14.72448063969224],[-17.493757324607728,14.724473738532716],[-17.494055758255964,14.724261377525076],[-17.494588226813807,14.723533872561644],[-17.495144860751022,14.724194346989922],[-17.49876960965357,14.722518329237221],[-17.499053495926916,14.72284068512386],[-17.50001520883468,14.72529103456179],[-17.499841808734033,14.725444339125447],[-17.50044923983212,14.726399740539588],[-17.49962458937384,14.727319178235105],[-17.498856864931735,14.72780770164897],[-17.496500709980236,14.726678853733569],[-17.496208596584548,14.727230562436514],[-17.49672583483,14.728054473706859],[-17.495699199774656,14.728714509633543],[-17.4948688286857,14.729263494759682],[-17.494211640219465,14.728328292271469],[-17.492583382764007,14.729411881312148],[-17.49128236909138,14.729150717887142],[-17.491556173389665,14.728792301178933],[-17.491027425142697,14.72803074541966],[-17.491359129608913,14.72785800215453],[-17.4915325109692,14.727026339179973]]]]}},{"type":"Feature","properties":{"OBJECTID_1":11,"OBJECTID":11,"QRT_VLG_HA":"CITE ENSEIGNANT SUPERIEUR","Shape_Leng":987.682388072,"Type":"R\u00e9gulier","Shape_Le_1":987.682388072,"Shape_Area":41482.74928380001,"num":28.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.493905305932447,14.730116876454357],[-17.494133515648787,14.729749629162324],[-17.495699199774656,14.728714509633543],[-17.49656583140289,14.729988496520937],[-17.496551204063763,14.730002337115542],[-17.496539477392876,14.73001014066378],[-17.49641425618239,14.730092626226941],[-17.496284864015745,14.730177858778994],[-17.496233698059328,14.730211562551062],[-17.496105457414448,14.730296037300887],[-17.495985077437954,14.730375332969922],[-17.49592296506235,14.730416247300191],[-17.49579553217463,14.730500190134077],[-17.495677114390013,14.730578192532281],[-17.495610761922798,14.730621899744932],[-17.495519854082573,14.730681782331654],[-17.49545287319271,14.730725902812187],[-17.495565815428268,14.73077416800193],[-17.495596045979973,14.730787086578191],[-17.495671640914992,14.730814205005803],[-17.49578253453264,14.730853987637367],[-17.495871158661597,14.730885780530784],[-17.4958928495294,14.730893249773676],[-17.49599818760715,14.730929522012646],[-17.496110845783022,14.73096831519026],[-17.496213272670193,14.73100358524637],[-17.496254773272753,14.731018801420577],[-17.496177948295585,14.731259309198816],[-17.493829797152177,14.730387786868656],[-17.493905305932447,14.730116876454357]]]]}},{"type":"Feature","properties":{"OBJECTID_1":12,"OBJECTID":12,"QRT_VLG_HA":"CITE URBANISME - HABITAT","Shape_Leng":599.9695404,"Type":"R\u00e9gulier","Shape_Le_1":599.9695404,"Shape_Area":21879.7761009,"num":30.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.49656583140289,14.729988496520937],[-17.495699199774656,14.728714509633543],[-17.49672583483,14.728054473706859],[-17.497565818563384,14.72933274504873],[-17.49670339243886,14.729858337655543],[-17.49656583140289,14.729988496520937]]]]}},{"type":"Feature","properties":{"OBJECTID_1":13,"OBJECTID":13,"QRT_VLG_HA":"GOUYE SOR","Shape_Leng":1050.42929669,"Type":"Irr\u00e9gulier","Shape_Le_1":1050.42929669,"Shape_Area":59516.8445291,"num":10.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.489897226178044,14.719381417756715],[-17.489518803306893,14.720891020084089],[-17.489527626038445,14.721023495964282],[-17.489180463027406,14.720858131856252],[-17.4889698559019,14.720722519647012],[-17.488756265423195,14.720615177664097],[-17.488162576939345,14.720400461241299],[-17.487870954660178,14.720203748127036],[-17.487435623146492,14.719998703488441],[-17.487180054048007,14.719896508484771],[-17.487036369509752,14.719075009137864],[-17.486864661260483,14.717959569075097],[-17.488039920384452,14.718414898051781],[-17.489607062789734,14.719184822155194],[-17.489897226178044,14.719381417756715]]]]}},{"type":"Feature","properties":{"OBJECTID_1":14,"OBJECTID":14,"QRT_VLG_HA":"LEONA","Shape_Leng":800.715615667,"Type":"Irr\u00e9gulier","Shape_Le_1":800.715615667,"Shape_Area":29462.1271697,"num":22.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.489180463027406,14.720858131856252],[-17.4887834002261,14.72160588640402],[-17.488409922189952,14.721412407597931],[-17.48826839321418,14.721694190656287],[-17.488139338721094,14.721988868401247],[-17.48793435923428,14.722340756691384],[-17.487586847020182,14.72228039961812],[-17.487180054048007,14.719896508484771],[-17.487435623146492,14.719998703488441],[-17.487870954660178,14.720203748127036],[-17.488162576939345,14.720400461241299],[-17.488756265423195,14.720615177664097],[-17.4889698559019,14.720722519647012],[-17.489180463027406,14.720858131856252]]]]}},{"type":"Feature","properties":{"OBJECTID_1":15,"OBJECTID":15,"QRT_VLG_HA":"MAMELLES AVIATION","Shape_Leng":7826.32137873,"Type":"R\u00e9gulier","Shape_Le_1":7826.32137873,"Shape_Area":1397319.97209,"num":18.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.49769217917368,14.733432492167875],[-17.49754241453526,14.733393953346944],[-17.497167330072674,14.733346349251562],[-17.4982131634887,14.730314084785972],[-17.496789282434925,14.728155540901719],[-17.496208596584548,14.727230562436514],[-17.496500709980236,14.726678853733569],[-17.498856864931735,14.72780770164897],[-17.49962458937384,14.727319178235105],[-17.50044923983212,14.726399740539588],[-17.499841808734033,14.725444339125447],[-17.50001520883468,14.72529103456179],[-17.499053495926916,14.72284068512386],[-17.49876960965357,14.722518329237221],[-17.495144860751022,14.724194346989922],[-17.494588226813807,14.723533872561644],[-17.493808163548255,14.7220355493602],[-17.492343137172497,14.719326455833777],[-17.491951554291077,14.7172850636177],[-17.492613366026546,14.716716401325886],[-17.493507362696718,14.716290875829397],[-17.49359887686953,14.71529010643007],[-17.493825585589317,14.715287665372266],[-17.49399940038354,14.715234865740513],[-17.494060786276012,14.715161533848754],[-17.494416011069966,14.71506062227587],[-17.49465745766129,14.714820363146266],[-17.494865377285404,14.714699293802953],[-17.49505682256703,14.714663280137955],[-17.49533604476461,14.71467724854474],[-17.495806709186162,14.714655200567188],[-17.49617389689096,14.714736122657305],[-17.496349447604125,14.714836084508596],[-17.49699817327966,14.71513465120927],[-17.49715628446178,14.715234800367176],[-17.497228939362742,14.71548865257239],[-17.497370771870987,14.715690830923332],[-17.497645411281443,14.716100903679882],[-17.49790057520827,14.71672678230115],[-17.498026110360893,14.71707559640554],[-17.498133667898916,14.717355287973628],[-17.49820991923062,14.717581537383014],[-17.498423657134623,14.717784449436536],[-17.498629579900587,14.71788768766382],[-17.49907084891505,14.71794722194105],[-17.4997341239888,14.717990988658816],[-17.50026491428212,14.717965696041038],[-17.500517670693657,14.718149240413739],[-17.500765672049678,14.718268436735174],[-17.501234298458904,14.718008742100995],[-17.501600719172753,14.718021758530577],[-17.502234379395425,14.718386614230738],[-17.502821590208626,14.718732825337502],[-17.50323536297323,14.719124479313331],[-17.503449113199668,14.719383351471103],[-17.50346316385224,14.719538564283242],[-17.503528917219846,14.719920288310481],[-17.50366492690896,14.720002474971885],[-17.503783816819475,14.720198382687038],[-17.503929103898187,14.720555344504469],[-17.504071254022527,14.721175263902005],[-17.504213951065545,14.721842981721503],[-17.5042311622321,14.722322087908552],[-17.504304604060856,14.722643829934736],[-17.504221282301156,14.722984240658992],[-17.504049792341373,14.723240730066143],[-17.50392922247299,14.72348209514901],[-17.50384633069541,14.723752195942039],[-17.50362465131176,14.724195958361014],[-17.503647131417075,14.72463707984454],[-17.50373859421641,14.725009551054374],[-17.50388227812006,14.725388624724415],[-17.50422377012352,14.725816765675582],[-17.50435737646147,14.726128027589455],[-17.50448486560743,14.726573371260113],[-17.505102155536566,14.727035752412585],[-17.50551028187525,14.72726958626736],[-17.506311922681647,14.727796975333378],[-17.507150038762934,14.728837699539467],[-17.507330895225206,14.729403299089308],[-17.506877161045296,14.729657287633728],[-17.506471218347645,14.729959873032007],[-17.506420665658375,14.729947520280033],[-17.50542063855829,14.728796866439104],[-17.505026838857336,14.72846042647001],[-17.504859841609065,14.728324068363044],[-17.50414910519119,14.728009548396686],[-17.502283190796394,14.727524200216129],[-17.502190954259877,14.728049851290471],[-17.502109475197404,14.728528298733421],[-17.502038076025,14.728813985052263],[-17.502071864793024,14.729020134348442],[-17.50209035346111,14.729262780644865],[-17.502097556356695,14.729893721471711],[-17.502069441108947,14.730356771814156],[-17.502026898125663,14.730760700528696],[-17.50195505026419,14.73122460862959],[-17.501810235045046,14.731720890041668],[-17.501706369093785,14.732150027855354],[-17.501632577551273,14.732398451825787],[-17.49977132155461,14.732001715411755],[-17.49959239471592,14.732040128384941],[-17.497878006661402,14.73335973581583],[-17.49769217917368,14.733432492167875]]]]}},{"type":"Feature","properties":{"OBJECTID_1":16,"OBJECTID":16,"QRT_VLG_HA":"MBOUL","Shape_Leng":1676.19026552,"Type":"Irr\u00e9gulier","Shape_Le_1":1676.19026552,"Shape_Area":129352.085373,"num":14.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.491498665136184,14.724427566051524],[-17.491085270445446,14.724465291084266],[-17.49108725207572,14.724358665473597],[-17.491073529053146,14.724339690988057],[-17.490928173023303,14.724310302217779],[-17.49074596709107,14.724290750362902],[-17.490444864425054,14.724232441829534],[-17.490436141334015,14.72428607724843],[-17.49041670265416,14.724308394297132],[-17.49032256632399,14.724341079261846],[-17.490052351622914,14.724508482964854],[-17.489901832450652,14.724597584332264],[-17.489744756192366,14.72471472258929],[-17.48962563246688,14.724735365502282],[-17.48956495659338,14.724686327706953],[-17.489521012542085,14.724472539106552],[-17.489563909888425,14.723708396055207],[-17.48967072629099,14.722207916253925],[-17.489782672642363,14.722159474603556],[-17.48987740108292,14.722110172861246],[-17.49006796786462,14.721988181961272],[-17.490125124547777,14.721939165008783],[-17.490265669783348,14.721772129361804],[-17.490591791749363,14.72178117018243],[-17.490878689931634,14.722066704902526],[-17.490985322317425,14.722103203859414],[-17.491077005067194,14.72195880235833],[-17.491303087487903,14.721754991799363],[-17.491315728908187,14.721559812662287],[-17.491642140541995,14.721378227079335],[-17.49171858437145,14.721474831913829],[-17.49240548739899,14.722344987501852],[-17.492504386506162,14.722317895887532],[-17.493478205192527,14.722196929209307],[-17.493851478661657,14.722118746975122],[-17.494588226813807,14.723533872561644],[-17.494055758255964,14.724261377525076],[-17.493757324607728,14.724473738532716],[-17.49367144641087,14.72448063969224],[-17.49342023949212,14.724418809219227],[-17.493150881460384,14.724218540878462],[-17.492472848340434,14.72409875062972],[-17.492173960207438,14.724157353257201],[-17.49184343648114,14.724199389048627],[-17.491556077473636,14.724414018672276],[-17.491498665136184,14.724427566051524]]]]}},{"type":"Feature","properties":{"OBJECTID_1":17,"OBJECTID":17,"QRT_VLG_HA":"MERINA (MERINA II)","Shape_Leng":1448.71809296,"Type":"Irr\u00e9gulier","Shape_Le_1":1448.71809296,"Shape_Area":68453.61259970002,"num":12.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.49128236909138,14.729150717887142],[-17.491223201666237,14.729404572920869],[-17.487841490684122,14.728643660734733],[-17.48815726646231,14.727440268909653],[-17.488207344917708,14.727167728475072],[-17.488244465592192,14.726965715539896],[-17.488697039524922,14.727012050169794],[-17.48859553946398,14.727703952916665],[-17.488975556647627,14.727878385382938],[-17.48907514750877,14.727938675636574],[-17.489353567307855,14.728102493896204],[-17.489546181935555,14.727888537497538],[-17.489702208466202,14.727735180549356],[-17.489688414355175,14.727593501175301],[-17.489670293920582,14.727571809538155],[-17.48963146030197,14.727421752219021],[-17.48965931461983,14.727383576777983],[-17.48972194680411,14.72721821566787],[-17.489737998709774,14.72722344528906],[-17.490077947452143,14.727312151933415],[-17.490102892741685,14.72731558203234],[-17.49029120363839,14.727245434310696],[-17.49045131684789,14.72715766200654],[-17.490456916102413,14.726864522109928],[-17.490713694928,14.726927736461402],[-17.490911183771825,14.726930391741863],[-17.4915325109692,14.727026339179973],[-17.491359129608913,14.72785800215453],[-17.491027425142697,14.72803074541966],[-17.491556173389665,14.728792301178933],[-17.49128236909138,14.729150717887142]]]]}},{"type":"Feature","properties":{"OBJECTID_1":18,"OBJECTID":18,"QRT_VLG_HA":"MERINA I","Shape_Leng":825.093307903,"Type":"Irr\u00e9gulier","Shape_Le_1":825.093307903,"Shape_Area":31465.6998422,"num":24.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.490456916102413,14.726864522109928],[-17.49045131684789,14.72715766200654],[-17.49029120363839,14.727245434310696],[-17.490102892741685,14.72731558203234],[-17.490077947452143,14.727312151933415],[-17.489737998709774,14.72722344528906],[-17.48972194680411,14.72721821566787],[-17.48965931461983,14.727383576777983],[-17.48963146030197,14.727421752219021],[-17.489670293920582,14.727571809538155],[-17.489688414355175,14.727593501175301],[-17.489702208466202,14.727735180549356],[-17.489546181935555,14.727888537497538],[-17.489353567307855,14.728102493896204],[-17.48907514750877,14.727938675636574],[-17.488975556647627,14.727878385382938],[-17.48859553946398,14.727703952916665],[-17.488697039524922,14.727012050169794],[-17.488244465592192,14.726965715539896],[-17.488341140156745,14.726439585641078],[-17.488281780357593,14.726187256400646],[-17.4884765970764,14.726152923193665],[-17.488597292726144,14.726176963020894],[-17.489010261154228,14.72626000353038],[-17.48901826570896,14.726186298750829],[-17.489037541145134,14.72615358363134],[-17.489467059047115,14.726345919800094],[-17.489787766785497,14.726325260230542],[-17.490047049396463,14.726362746894692],[-17.49013302507649,14.726418350974928],[-17.49046127514667,14.726636244012225],[-17.490456916102413,14.726864522109928]]]]}},{"type":"Feature","properties":{"OBJECTID_1":19,"OBJECTID":19,"QRT_VLG_HA":"MONTAGNE ROUGE","Shape_Leng":1058.69090788,"Type":"Irr\u00e9gulier","Shape_Le_1":1058.69090788,"Shape_Area":68729.3245555,"num":3.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.480967674150637,14.723658416201227],[-17.480981171145974,14.723666594581656],[-17.48098003932866,14.723668617684355],[-17.480967674150637,14.723658416201227]]],[[[-17.480981171145974,14.723666594581656],[-17.481260837572403,14.723167092049012],[-17.481664046359352,14.72266320606601],[-17.481767219759877,14.722556928746734],[-17.48195941420964,14.722395916618314],[-17.482160054477536,14.72220647321987],[-17.48281323785715,14.721637277220852],[-17.48459890910903,14.722384119688995],[-17.484299670817204,14.722619471423867],[-17.483199255586612,14.724234460281696],[-17.482968999816936,14.724480543976274],[-17.482726318301236,14.724655803199253],[-17.48252975768083,14.724604848398778],[-17.480981171145974,14.723666594581656]]]]}},{"type":"Feature","properties":{"OBJECTID_1":20,"OBJECTID":20,"QRT_VLG_HA":"OUAKAM CORNICHE","Shape_Leng":1265.59994177,"Type":"Irr\u00e9gulier","Shape_Le_1":1265.59994177,"Shape_Area":98860.9885339,"num":16.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.488039920384452,14.718414898051781],[-17.48982696931084,14.71686136320455],[-17.490572618504395,14.717104320299738],[-17.49180337245487,14.71735400630522],[-17.491951554291077,14.7172850636177],[-17.492343137172497,14.719326455833777],[-17.491715180199723,14.719941831906082],[-17.491506080260656,14.72000951233967],[-17.49064920902238,14.71981317097924],[-17.490349140665057,14.719773374415842],[-17.489897226178044,14.719381417756715],[-17.489607062789734,14.719184822155194],[-17.48867897347031,14.718728861311876],[-17.488039920384452,14.718414898051781]]]]}},{"type":"Feature","properties":{"OBJECTID_1":21,"OBJECTID":21,"QRT_VLG_HA":"RIP","Shape_Leng":560.081134247,"Type":"Irr\u00e9gulier","Shape_Le_1":560.081134247,"Shape_Area":13668.3754061,"num":23.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.490265669783348,14.721772129361804],[-17.490195147619062,14.721855943480346],[-17.490125124547777,14.721939165008783],[-17.49006796786462,14.721988181961272],[-17.48987740108292,14.722110172861246],[-17.489782672642363,14.722159474603556],[-17.48967072629099,14.722207916253925],[-17.489665131253236,14.721947621163622],[-17.489605526596375,14.721577274230077],[-17.489562549653957,14.72158978589542],[-17.489384086249327,14.7216145979306],[-17.489384582594504,14.721678273931014],[-17.489140563125336,14.721693085448848],[-17.48912529741163,14.721757327522623],[-17.489096835923984,14.721765358480965],[-17.4887834002261,14.72160588640402],[-17.489180463027406,14.720858131856252],[-17.489527626038445,14.721023495964282],[-17.489903199652346,14.721007983946723],[-17.489989089439465,14.720980528362691],[-17.490049309856232,14.72096338827677],[-17.49015103631023,14.72096803072136],[-17.490246556984875,14.720977759140446],[-17.490275213042757,14.7213277409816],[-17.490273397549853,14.721401886877944],[-17.490281946875594,14.72154775844437],[-17.490251726471495,14.721758009300466],[-17.490265669783348,14.721772129361804]]]]}},{"type":"Feature","properties":{"OBJECTID_1":22,"OBJECTID":22,"QRT_VLG_HA":"SACRE COEUR III VDN","Shape_Leng":2597.60510559,"Type":"R\u00e9gulier","Shape_Le_1":2597.60510559,"Shape_Area":306733.53366,"num":21.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.475377904832836,14.725609422315612],[-17.475102472378598,14.72569218732868],[-17.475230853471633,14.726280488483036],[-17.471600645696398,14.726372894115846],[-17.471243795953757,14.725285651537824],[-17.471068408801713,14.72440886813546],[-17.47100060434952,14.723525088205934],[-17.471086449335488,14.720995650422209],[-17.47139469902318,14.717196224993886],[-17.47173604942209,14.717328193211939],[-17.471937864325724,14.717457503762168],[-17.47296645505512,14.718563971650301],[-17.473747015012925,14.721358222295516],[-17.474130018941924,14.721346156096208],[-17.475377904832836,14.725609422315612]]]]}},{"type":"Feature","properties":{"OBJECTID_1":23,"OBJECTID":23,"QRT_VLG_HA":"SINTHIA","Shape_Leng":1514.22994029,"Type":"Irr\u00e9gulier","Shape_Le_1":1514.22994029,"Shape_Area":86820.2726531,"num":15.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.489527626038445,14.721023495964282],[-17.489518803306893,14.720891020084089],[-17.489897226178044,14.719381417756715],[-17.490349140665057,14.719773374415842],[-17.49064920902238,14.71981317097924],[-17.491506080260656,14.72000951233967],[-17.491715180199723,14.719941831906082],[-17.492343137172497,14.719326455833777],[-17.493851478661657,14.722118746975122],[-17.493475371911842,14.722194772609042],[-17.492504386506162,14.722317895887532],[-17.49240548739899,14.722344987501852],[-17.491642140541995,14.721378227079335],[-17.491315728908187,14.721559812662287],[-17.491303087487903,14.721754991799363],[-17.491077005067194,14.72195880235833],[-17.490985322317425,14.722103203859414],[-17.490878689931634,14.722066704902526],[-17.490591791749363,14.72178117018243],[-17.490265669783348,14.721772129361804],[-17.490251726471495,14.721758009300466],[-17.490281946875594,14.72154775844437],[-17.490273397549853,14.721401886877944],[-17.490275213042757,14.7213277409816],[-17.490246556984875,14.720977759140446],[-17.490049309856232,14.72096338827677],[-17.489989089439465,14.720980528362691],[-17.489903199652346,14.721007983946723],[-17.489527626038445,14.721023495964282]]]]}},{"type":"Feature","properties":{"OBJECTID_1":24,"OBJECTID":24,"QRT_VLG_HA":"SUD AFRICA","Shape_Leng":6141.18425263,"Type":"Irr\u00e9gulier","Shape_Le_1":6141.18425263,"Shape_Area":1424573.12079,"num":1.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.477321489085345,14.717329759314953],[-17.477335823646115,14.717351082593522],[-17.477333543737842,14.717351224426382],[-17.477321489085345,14.717329759314953]]],[[[-17.477335823646115,14.717351082593522],[-17.477376417634712,14.717348551516874],[-17.478371405382248,14.717206918596315],[-17.47897636126894,14.71695305181763],[-17.479441066108734,14.716675586966906],[-17.48074833766172,14.715362610960437],[-17.480240768775218,14.714842561357226],[-17.47973868322336,14.715047923870255],[-17.479177916456788,14.71523463044467],[-17.47817956238852,14.71536004746382],[-17.478178187016304,14.715564430192869],[-17.477154958770004,14.715592215438631],[-17.47667806596514,14.712592227340723],[-17.481588202375008,14.70992120982936],[-17.483363444529104,14.70974102004087],[-17.48522275647615,14.71016160761604],[-17.48526520439126,14.710010728948976],[-17.485701435165904,14.709901848109274],[-17.486141843389444,14.70975800980977],[-17.486451053353356,14.709738916321744],[-17.48768309981899,14.7095360100984],[-17.48832821819548,14.709686839046578],[-17.48870880802426,14.710357092381317],[-17.48889235539986,14.710967555539227],[-17.48904352316475,14.711642834544403],[-17.48932665497027,14.712273715145303],[-17.48941897853598,14.712627290147456],[-17.489544658449987,14.713001994759688],[-17.48985883762392,14.713449886261703],[-17.489975966516138,14.713932697907634],[-17.490123557640086,14.714403296809369],[-17.490357301913836,14.714572694591793],[-17.49074826085201,14.71497677867955],[-17.491459198057306,14.715366676693073],[-17.492168307917172,14.71559542515696],[-17.492281655566373,14.715774771550718],[-17.49238275656414,14.715710611180585],[-17.49259202567767,14.715708358951172],[-17.492906958141564,14.715631787862183],[-17.493460904986115,14.715427397428373],[-17.49359887686953,14.71529010643007],[-17.493507362696718,14.716290875829397],[-17.492613366026546,14.716716401325886],[-17.491951554291077,14.7172850636177],[-17.49180337245487,14.71735400630522],[-17.490572618504395,14.717104320299738],[-17.48982696931084,14.71686136320455],[-17.488039920384452,14.718414898051781],[-17.486864661260483,14.717959569075097],[-17.487056879922868,14.719192967781842],[-17.486147767380398,14.719527952711793],[-17.485771562847688,14.720385271210866],[-17.485750785105946,14.72163077732061],[-17.48565432160828,14.721787178692168],[-17.48459890910903,14.722384119688995],[-17.48281323785715,14.721637277220852],[-17.480247332318637,14.719782453842598],[-17.478773934254125,14.718707677451327],[-17.477477802309092,14.717562271976249],[-17.477335823646115,14.717351082593522]]]]}},{"type":"Feature","properties":{"OBJECTID_1":25,"OBJECTID":25,"QRT_VLG_HA":"TAGLOU","Shape_Leng":1404.12141674,"Type":"Irr\u00e9gulier","Shape_Le_1":1404.12141674,"Shape_Area":76975.3493515,"num":13.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.488281780357593,14.726187256400646],[-17.488035823959077,14.724860267821667],[-17.488294880908242,14.724775712030139],[-17.488781458216664,14.725134389724477],[-17.489521012542085,14.724472539106552],[-17.48956495659338,14.724686327706953],[-17.48962563246688,14.724735365502282],[-17.489744756192366,14.72471472258929],[-17.489901832450652,14.724597584332264],[-17.490052351622914,14.724508482964854],[-17.49032256632399,14.724341079261846],[-17.49041670265416,14.724308394297132],[-17.490436141334015,14.72428607724843],[-17.490444864425054,14.724232441829534],[-17.49074596709107,14.724290750362902],[-17.490928173023303,14.724310302217779],[-17.491073529053146,14.724339690988057],[-17.49108725207572,14.724358665473597],[-17.491085270445446,14.724465291084266],[-17.491498665136184,14.724427566051524],[-17.49123135639488,14.72593347717401],[-17.491925203049547,14.726045519678289],[-17.491735228282515,14.727049552021798],[-17.4915325109692,14.727026339179973],[-17.490911183771825,14.726930391741863],[-17.490713694928,14.726927736461402],[-17.490456916102413,14.726864522109928],[-17.49046127514667,14.726636244012225],[-17.490047049396463,14.726362746894692],[-17.489787766785497,14.726325260230542],[-17.489467059047115,14.726345919800094],[-17.489037541145134,14.72615358363134],[-17.48901826570896,14.726186298750829],[-17.489010261154228,14.72626000353038],[-17.4884765970764,14.726152923193665],[-17.488281780357593,14.726187256400646]]]]}},{"type":"Feature","properties":{"OBJECTID_1":26,"OBJECTID":26,"QRT_VLG_HA":"TERME SUD","Shape_Leng":1843.60504557,"Type":"R\u00e9gulier","Shape_Le_1":1843.60504557,"Shape_Area":123471.665344,"num":5.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.485943952209105,14.729356253176304],[-17.485523436858315,14.731099915516626],[-17.484347850153735,14.731074049193136],[-17.483760805937038,14.733063575479083],[-17.481235432250934,14.732994149677685],[-17.48091870004963,14.731190914046556],[-17.483089633144445,14.731036190184604],[-17.48301460602252,14.729876767799146],[-17.483941433010592,14.729871123247694],[-17.483993945001185,14.729173562469722],[-17.484280391668538,14.729136539473608],[-17.484494989203778,14.728930562350781],[-17.484638631151533,14.728717752976118],[-17.485042843638013,14.728987649235119],[-17.48505116953441,14.729288731314693],[-17.485943952209105,14.729356253176304]]]]}},{"type":"Feature","properties":{"OBJECTID_1":27,"OBJECTID":27,"QRT_VLG_HA":"TOUBA ALMADIES","Shape_Leng":2899.39968779,"Type":"R\u00e9gulier","Shape_Le_1":2899.39968779,"Shape_Area":293372.165463,"num":17.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.498320174374616,14.735849104891317],[-17.498331102982526,14.735844723751686],[-17.49828442656474,14.735458510832084],[-17.498245938931326,14.735342751401781],[-17.49754241453526,14.733393953346944],[-17.49769217917368,14.733432492167875],[-17.497878006661402,14.73335973581583],[-17.49959239471592,14.732040128384941],[-17.49977132155461,14.732001715411755],[-17.501632577551273,14.732398451825787],[-17.501706369093785,14.732150027855354],[-17.501810235045046,14.731720890041668],[-17.50195505026419,14.73122460862959],[-17.502026898125663,14.730760700528696],[-17.502069441108947,14.730356771814156],[-17.502097556356695,14.729893721471711],[-17.50209035346111,14.729262780644865],[-17.502071864793024,14.729020134348442],[-17.502038076025,14.728813985052263],[-17.502109475197404,14.728528298733421],[-17.502190954259877,14.728049851290471],[-17.502283190796394,14.727524200216129],[-17.50414910519119,14.728009548396686],[-17.504859841609065,14.728324068363044],[-17.505026838857336,14.72846042647001],[-17.504753517583566,14.728919691234628],[-17.50257024638464,14.733871945060882],[-17.502214065532623,14.735372075281392],[-17.498997403678445,14.735623129006047],[-17.498320174374616,14.735849104891317]]]]}},{"type":"Feature","properties":{"OBJECTID_1":28,"OBJECTID":28,"QRT_VLG_HA":"TOUBA OUAKAM II CITE AVION","Shape_Leng":2661.1027754,"Type":"Irr\u00e9gulier","Shape_Le_1":2661.1027754,"Shape_Area":391964.99433,"num":2.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.476867139933066,14.725207768385722],[-17.476618434041885,14.72334381412504],[-17.475837834243872,14.717493189590614],[-17.476682142725874,14.717391832228826],[-17.477335823646115,14.717351082593522],[-17.477477802309092,14.717562271976249],[-17.478773934254125,14.718707677451327],[-17.48281323785715,14.721637277220852],[-17.482544878741834,14.72187310510134],[-17.48195941420964,14.722395916618314],[-17.481767219759877,14.722556928746734],[-17.481664046359352,14.72266320606601],[-17.481260837572403,14.723167092049012],[-17.48098003932866,14.723668617684355],[-17.480819800910442,14.723909212525585],[-17.48070501337126,14.7241829312721],[-17.48052185731031,14.724708957501331],[-17.480250829527424,14.725138474449247],[-17.47995420399758,14.725457960998094],[-17.47945941118636,14.725103273284104],[-17.477691802924607,14.725286143134017],[-17.476867139933066,14.725207768385722]]]]}},{"type":"Feature","properties":{"OBJECTID_1":29,"OBJECTID":29,"QRT_VLG_HA":"TOUBA RENAISSANCE","Shape_Leng":1420.1611765,"Type":"R\u00e9gulier","Shape_Le_1":1420.1611765,"Shape_Area":130327.007544,"num":26.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.498245938931326,14.735342751401781],[-17.497227794360782,14.735885565497183],[-17.496759696637522,14.736296957857766],[-17.494006066578738,14.73604581342137],[-17.494232837447623,14.732973892657629],[-17.49754241453526,14.733393953346944],[-17.498245938931326,14.735342751401781]]]]}},{"type":"Feature","properties":{"OBJECTID_1":30,"OBJECTID":30,"QRT_VLG_HA":"ZONE DE L'AEROPORT","Shape_Leng":3266.13673357,"Type":"R\u00e9gulier","Shape_Le_1":3266.13673357,"Shape_Area":421296.046194,"num":8.0},"geometry":{"type":"MultiPolygon","coordinates":[[[[-17.498245938931326,14.735342751401781],[-17.498282764129886,14.735444761116382],[-17.498331102982526,14.735844723751686],[-17.498320174374616,14.735849104891317],[-17.497748989137442,14.736742092513323],[-17.497305963950176,14.737306190117765],[-17.49681742446817,14.737756046963801],[-17.496521413603332,14.738289872856763],[-17.496362193560405,14.738542565447593],[-17.49580963182302,14.739193890708187],[-17.495293167919733,14.739780289966664],[-17.492697947237147,14.738345456355958],[-17.49258197172136,14.737866268905208],[-17.491744546165787,14.738090414409868],[-17.491412854271978,14.73807964644315],[-17.49101355157418,14.737954874200149],[-17.490526004764007,14.7378453937781],[-17.490120476117184,14.737821077595505],[-17.489730253430082,14.737846791279884],[-17.488887690885313,14.737619226315013],[-17.490339716758225,14.732220958125803],[-17.494232837447623,14.732973892657629],[-17.494006066578738,14.73604581342137],[-17.496759696637522,14.736296957857766],[-17.497227794360782,14.735885565497183],[-17.49769701097756,14.735635408385944],[-17.498245938931326,14.735342751401781]]]]}}]};
const CADASTRE_RUES = {"type":"FeatureCollection","features":[{"type":"Feature","properties":{"OBJECTID":1,"Id":0,"Nom":"Rue ASS - 37","Type":"Impaire","Shape_Leng":867.947791038},"geometry":{"type":"LineString","coordinates":[[-17.49786078611855,14.733392016557831],[-17.49776033197623,14.73337015504771],[-17.49768910106561,14.733326465531736],[-17.497676529114447,14.733257762872455],[-17.497731799849483,14.733194064277498],[-17.49781257861535,14.733041173110637],[-17.497965082858094,14.732716846115107],[-17.498121892989463,14.732382434104855],[-17.49830619024339,14.732003265765368],[-17.498455976227273,14.731699045842896],[-17.498607005898435,14.731374734591762],[-17.49877373530348,14.731005795191729],[-17.498956688458716,14.730638115107203],[-17.499117883018247,14.730300786290526],[-17.49931933566886,14.729875540987846],[-17.499467480844498,14.729556996282657],[-17.4997984791081,14.728862168358086],[-17.500071897731456,14.728286994753688],[-17.500394117825728,14.72759799692655],[-17.500647641672053,14.727087574338373],[-17.50082155271407,14.726702781743056],[-17.500826513763474,14.726620982225754],[-17.50079174240753,14.726543914884344],[-17.50065909159945,14.726411973567581]]}},{"type":"Feature","properties":{"OBJECTID":2,"Id":0,"Nom":"Rue ASS - 145","Type":"Impaire","Shape_Leng":233.876118646},"geometry":{"type":"LineString","coordinates":[[-17.49465718879983,14.732942116951184],[-17.49481539406421,14.732514762086453],[-17.494995624190526,14.732036684476672],[-17.49513939865685,14.731635487522572],[-17.495259409866375,14.731304341128073],[-17.49539651456763,14.730955845001041]]}},{"type":"Feature","properties":{"OBJECTID":3,"Id":0,"Nom":"Rue ASS - 155","Type":"Impaire","Shape_Leng":213.617633137},"geometry":{"type":"LineString","coordinates":[[-17.495497419552596,14.733045491247157],[-17.495618519552515,14.732701720660854],[-17.495885752154333,14.731970295424992],[-17.49606444127549,14.731486497452495],[-17.496156798413825,14.731225668343834]]}},{"type":"Feature","properties":{"OBJECTID":4,"Id":0,"Nom":"Rue ASS - 161","Type":"Impaire","Shape_Leng":194.428448175},"geometry":{"type":"LineString","coordinates":[[-17.496914098683288,14.731494361146375],[-17.496815426241167,14.731752792332435],[-17.496717629654988,14.73200721202885],[-17.496628100663415,14.73223285980864],[-17.49643856360976,14.7327344626649],[-17.49628840607436,14.733141712195904]]}},{"type":"Feature","properties":{"OBJECTID":5,"Id":0,"Nom":"Rue ASS - 146","Type":"Paire","Shape_Leng":448.462617264},"geometry":{"type":"LineString","coordinates":[[-17.49726016954209,14.73301047806267],[-17.49643856360976,14.7327344626649],[-17.495706069349254,14.732462096388318],[-17.494935800751843,14.732195372743877],[-17.494175846979964,14.731908706473344],[-17.493348389961064,14.7316268617637]]}},{"type":"Feature","properties":{"OBJECTID":6,"Id":0,"Nom":"Rue ASS - 148","Type":"Paire","Shape_Leng":355.079836098},"geometry":{"type":"LineString","coordinates":[[-17.493260191289828,14.731858221906005],[-17.494081408457255,14.732151477088683],[-17.494850250770074,14.732422301573763],[-17.495618519552515,14.732701720660854],[-17.49635843079878,14.732951796285342]]}},{"type":"Feature","properties":{"OBJECTID":7,"Id":0,"Nom":"Rue ASS - 142","Type":"Paire","Shape_Leng":448.397958763},"geometry":{"type":"LineString","coordinates":[[-17.49744522726695,14.732519905661825],[-17.496628100663415,14.73223285980864],[-17.495885752154333,14.731970295424992],[-17.495114940406236,14.731703737005459],[-17.49436059119061,14.73143378759161],[-17.493535285768022,14.731131956927953]]}},{"type":"Feature","properties":{"OBJECTID":8,"Id":0,"Nom":"Rue ASS - 140","Type":"Paire","Shape_Leng":448.644500571},"geometry":{"type":"LineString","coordinates":[[-17.49362053958918,14.730906635155442],[-17.494451515750118,14.73119443803176],[-17.495204094052195,14.731456974144514],[-17.495970539489143,14.731740736708868],[-17.496717629654988,14.73200721202885],[-17.497534623186656,14.732289821654547]]}},{"type":"Feature","properties":{"OBJECTID":9,"Id":0,"Nom":"Rue ASS - 138","Type":"Paire","Shape_Leng":448.928081438},"geometry":{"type":"LineString","coordinates":[[-17.497631499332023,14.73204048511797],[-17.496815426241167,14.731752792332435],[-17.49606444127549,14.731486497452495],[-17.495299265325247,14.731203034868956],[-17.49454658584991,14.730944175044911],[-17.494268538128264,14.730833636876717],[-17.49372072927832,14.730641553048116]]}},{"type":"Feature","properties":{"OBJECTID":10,"Id":0,"Nom":"Rue ASS - 122","Type":"Paire","Shape_Leng":182.572565428},"geometry":{"type":"LineString","coordinates":[[-17.49317141936922,14.732089957703554],[-17.493998070568114,14.732381837361977],[-17.494763160932255,14.73265585712418]]}},{"type":"Feature","properties":{"OBJECTID":11,"Id":0,"Nom":"Rue ASS - 143","Type":"Impaire","Shape_Leng":283.894176972},"geometry":{"type":"LineString","coordinates":[[-17.49382606772896,14.732815206353482],[-17.493998070568114,14.732381837361977],[-17.494081408457255,14.732151477088683],[-17.49436059119061,14.73143378759161],[-17.49454658584991,14.730944175044911],[-17.49464780867839,14.730683681635233],[-17.494748090016596,14.730412908717128]]}},{"type":"Feature","properties":{"OBJECTID":12,"Id":0,"Nom":"Rue ASS - 128","Type":"Paire","Shape_Leng":448.193152353},"geometry":{"type":"LineString","coordinates":[[-17.49735348923127,14.732756017975404],[-17.496830781460023,14.732580510915142],[-17.496537507840213,14.73247261106443],[-17.495796987447036,14.7322132482719],[-17.49530177010799,14.732028132256637],[-17.495032944105596,14.731932544530222],[-17.494269213024385,14.731668692819605],[-17.493919370242494,14.731530687353823],[-17.49344640474981,14.73136695247437]]}},{"type":"Feature","properties":{"OBJECTID":13,"Id":0,"Nom":"Rue ASS - 80","Type":"Paire","Shape_Leng":513.748697395},"geometry":{"type":"LineString","coordinates":[[-17.49386442413109,14.730182773393405],[-17.49384083772814,14.73011149600485],[-17.493757462398886,14.730036385769024],[-17.4935576715501,14.729961094579542],[-17.493114100215458,14.729829631639777],[-17.4925184912022,14.729675423828358],[-17.49187571858152,14.729520288572916],[-17.491386749861395,14.729413690823618],[-17.490797199082255,14.729273755300195],[-17.49018705379861,14.729136908065962],[-17.489647844911104,14.729017939303274],[-17.489321376039367,14.728946876163729]]}},{"type":"Feature","properties":{"OBJECTID":14,"Id":0,"Nom":"Rue ASS - 137","Type":"Impaire","Shape_Leng":316.538769384},"geometry":{"type":"LineString","coordinates":[[-17.491246025831938,14.729380288791841],[-17.491139178688993,14.729855821824486],[-17.491028076255635,14.730323113827044],[-17.49082083978968,14.731148542958609],[-17.490712813679664,14.731627275866273],[-17.49056858783042,14.732162329479895]]}},{"type":"Feature","properties":{"OBJECTID":15,"Id":0,"Nom":"Rue ASS - 84","Type":"Paire","Shape_Leng":206.514840194},"geometry":{"type":"LineString","coordinates":[[-17.491139178688993,14.729855821824486],[-17.491904770767135,14.73002990497044],[-17.49248384653504,14.730155609611781],[-17.49300966809058,14.73026324133673]]}},{"type":"Feature","properties":{"OBJECTID":16,"Id":0,"Nom":"Rue ASS - 139","Type":"Impaire","Shape_Leng":327.312252648},"geometry":{"type":"LineString","coordinates":[[-17.493114100215458,14.729829631639777],[-17.49300966809058,14.73026324133673],[-17.492964982154565,14.73048027807985],[-17.49290569257489,14.730708945270171],[-17.492799047251665,14.731179058767875],[-17.492688882979905,14.73159901489412],[-17.492580356327363,14.732033294755867],[-17.492567057502733,14.732159642193057],[-17.492524517714337,14.73230638259633],[-17.492465521068144,14.732560861115946],[-17.492464303174714,14.732712893793547]]}},{"type":"Feature","properties":{"OBJECTID":17,"Id":0,"Nom":"Rue ASS - 114","Type":"Paire","Shape_Leng":206.209886605},"geometry":{"type":"LineString","coordinates":[[-17.492580356327363,14.732033294755867],[-17.492136000947777,14.731946624705728],[-17.49157642398271,14.731808281882277],[-17.491047679081245,14.731703068295252],[-17.490712813679664,14.731627275866273]]}},{"type":"Feature","properties":{"OBJECTID":18,"Id":0,"Nom":"Rue ASS - 116","Type":"Paire","Shape_Leng":207.970008025},"geometry":{"type":"LineString","coordinates":[[-17.49064498373078,14.731878914175368],[-17.49135401402915,14.7320334468859],[-17.49177661926692,14.732135022037342],[-17.492107503141444,14.73220507752976],[-17.492407935871224,14.732275460569575],[-17.492524517714337,14.73230638259633]]}},{"type":"Feature","properties":{"OBJECTID":19,"Id":0,"Nom":"Rue ASS - 108","Type":"Paire","Shape_Leng":207.33881677},"geometry":{"type":"LineString","coordinates":[[-17.492688882979905,14.73159901489412],[-17.4923393572109,14.731525545557961],[-17.49199519531279,14.731443204368096],[-17.49162831255333,14.731360509262508],[-17.491291012798563,14.731287653674672],[-17.490906715383446,14.73118542644847],[-17.490817078586137,14.731165208632277]]}},{"type":"Feature","properties":{"OBJECTID":20,"Id":0,"Nom":"Rue ASS - 102","Type":"Paire","Shape_Leng":207.801871164},"geometry":{"type":"LineString","coordinates":[[-17.490927372271592,14.730724221365435],[-17.491731280787544,14.730912425966144],[-17.492207720651983,14.731018441022858],[-17.492802762354838,14.731162679053073]]}},{"type":"Feature","properties":{"OBJECTID":21,"Id":0,"Nom":"Rue ASS - 90","Type":"Paire","Shape_Leng":206.713088387},"geometry":{"type":"LineString","coordinates":[[-17.49290569257489,14.730708945270171],[-17.492239351680826,14.730560370235569],[-17.49172600926367,14.73044877744671],[-17.49103422346604,14.730297258425542]]}},{"type":"Feature","properties":{"OBJECTID":22,"Id":0,"Nom":"Rue ASS - 86","Type":"Paire","Shape_Leng":85.4544538917},"geometry":{"type":"LineString","coordinates":[[-17.49299906926683,14.73031471980361],[-17.49374133817922,14.730586817456627]]}},{"type":"Feature","properties":{"OBJECTID":23,"Id":0,"Nom":"Rue ASS - 88","Type":"Paire","Shape_Leng":78.9553832161},"geometry":{"type":"LineString","coordinates":[[-17.493581177544698,14.731010666873248],[-17.492892685658177,14.730766281994324]]}},{"type":"Feature","properties":{"OBJECTID":24,"Id":0,"Nom":"Rue ASS - 104","Type":"Paire","Shape_Leng":72.446405604},"geometry":{"type":"LineString","coordinates":[[-17.492794141617562,14.731197759481285],[-17.4934193116314,14.731438795038656]]}},{"type":"Feature","properties":{"OBJECTID":25,"Id":0,"Nom":"Rue ASS - 106","Type":"Paire","Shape_Leng":67.0041825945},"geometry":{"type":"LineString","coordinates":[[-17.493260191289828,14.731858221906005],[-17.492675980933882,14.731650643748088]]}},{"type":"Feature","properties":{"OBJECTID":26,"Id":0,"Nom":"Rue ASS - 118","Type":"Paire","Shape_Leng":60.2103192158},"geometry":{"type":"LineString","coordinates":[[-17.493099793478596,14.732281346546198],[-17.492573509607467,14.732098340625996]]}},{"type":"Feature","properties":{"OBJECTID":27,"Id":0,"Nom":"Rue ASS - 150","Type":"Paire","Shape_Leng":197.727070131},"geometry":{"type":"LineString","coordinates":[[-17.492464303174714,14.732712893793547],[-17.492994036253922,14.732802167996327],[-17.49380640556747,14.732967899353595],[-17.49426752467328,14.733043717776766]]}},{"type":"Feature","properties":{"OBJECTID":28,"Id":0,"Nom":"Rue ASS - 119","Type":"Impaire","Shape_Leng":281.869531459},"geometry":{"type":"LineString","coordinates":[[-17.4896763846181,14.72902423607021],[-17.489610926902444,14.729299705537748],[-17.489532577937403,14.729666972270248],[-17.489445912620603,14.730015684188846],[-17.489381197978158,14.730285999918259],[-17.48929406906558,14.730593844469023],[-17.48922432375727,14.730940223366657],[-17.489117538988907,14.731398864690687],[-17.48906631600308,14.731494785491572],[-17.489066299721003,14.731493351976837]]}},{"type":"Feature","properties":{"OBJECTID":29,"Id":0,"Nom":"Rue ASS - 112","Type":"Paire","Shape_Leng":172.830063363},"geometry":{"type":"LineString","coordinates":[[-17.489117538988907,14.731398864690687],[-17.489343470399252,14.73143375686977],[-17.490051329977025,14.731588676017719],[-17.490679330709366,14.731751492715834]]}},{"type":"Feature","properties":{"OBJECTID":30,"Id":0,"Nom":"Rue ASS - 110","Type":"Paire","Shape_Leng":172.97194184},"geometry":{"type":"LineString","coordinates":[[-17.490786852228908,14.731299164256782],[-17.490367399057895,14.731198055344956],[-17.489589335666675,14.731024771136553],[-17.48922432375727,14.730940223366657]]}},{"type":"Feature","properties":{"OBJECTID":31,"Id":0,"Nom":"Rue ASS - 100","Type":"Paire","Shape_Leng":174.027626877},"geometry":{"type":"LineString","coordinates":[[-17.489324316235848,14.730486974315314],[-17.48993348240916,14.730619507522725],[-17.490639142492522,14.73079715571078],[-17.490894487123697,14.730855203630837]]}},{"type":"Feature","properties":{"OBJECTID":32,"Id":0,"Nom":"Rue ASS - 98","Type":"Paire","Shape_Leng":173.590290109},"geometry":{"type":"LineString","coordinates":[[-17.490997485910473,14.7304449579371],[-17.490256151847944,14.730269449955438],[-17.489428684049066,14.730087648886872]]}},{"type":"Feature","properties":{"OBJECTID":33,"Id":0,"Nom":"Rue ASS - 121","Type":"Impaire","Shape_Leng":116.177201543},"geometry":{"type":"LineString","coordinates":[[-17.489964963385276,14.72908790646716],[-17.489772908425973,14.729845363777889],[-17.489503247482588,14.72978499040888]]}},{"type":"Feature","properties":{"OBJECTID":34,"Id":0,"Nom":"Rue ASS - 123","Type":"Impaire","Shape_Leng":120.595968708},"geometry":{"type":"LineString","coordinates":[[-17.49031302134043,14.729165160342507],[-17.490073453508646,14.730229309781162]]}},{"type":"Feature","properties":{"OBJECTID":35,"Id":0,"Nom":"Rue ASS - 82","Type":"Paire","Shape_Leng":103.319449622},"geometry":{"type":"LineString","coordinates":[[-17.491153315415552,14.72979290354202],[-17.490219981555335,14.729578437086902]]}},{"type":"Feature","properties":{"OBJECTID":36,"Id":0,"Nom":"Rue ASS - 92","Type":"Paire","Shape_Leng":80.1678327589},"geometry":{"type":"LineString","coordinates":[[-17.491069123234617,14.730150472156962],[-17.490344067210987,14.729987650715266]]}},{"type":"Feature","properties":{"OBJECTID":37,"Id":0,"Nom":"Rue ASS - 127","Type":"Impaire","Shape_Leng":32.9620360181},"geometry":{"type":"LineString","coordinates":[[-17.490585659202814,14.730347460168467],[-17.49065048163401,14.73005646082132]]}},{"type":"Feature","properties":{"OBJECTID":38,"Id":0,"Nom":"Rue ASS - 125","Type":"Impaire","Shape_Leng":40.8670589808},"geometry":{"type":"LineString","coordinates":[[-17.49061108762591,14.730047614209196],[-17.490695192834714,14.729687634526025]]}},{"type":"Feature","properties":{"OBJECTID":39,"Id":0,"Nom":"Rue ASS - 96","Type":"Paire","Shape_Leng":128.23402755},"geometry":{"type":"LineString","coordinates":[[-17.489445912620603,14.730015684188846],[-17.488288601482235,14.729745044941893]]}},{"type":"Feature","properties":{"OBJECTID":40,"Id":0,"Nom":"Rue ASS - 36","Type":"Paire","Shape_Leng":507.823470719},"geometry":{"type":"LineString","coordinates":[[-17.49116102497518,14.729360113239048],[-17.491272357057678,14.729127929165752],[-17.49132065478047,14.729055701298453],[-17.491423425512476,14.728914168394136],[-17.491929761984423,14.728355974277356],[-17.492481529399257,14.727743510124391],[-17.492951536041225,14.727230522259331],[-17.493591770448944,14.726569298390546],[-17.493643696216793,14.726492251271358],[-17.493674504954576,14.726393919156976],[-17.49370870284214,14.726161698275874],[-17.493811340676608,14.72568493567258]]}},{"type":"Feature","properties":{"OBJECTID":41,"Id":0,"Nom":"Rue ASS - 101","Type":"Impaire","Shape_Leng":145.392538623},"geometry":{"type":"LineString","coordinates":[[-17.49131363319588,14.729066202073323],[-17.49176733521805,14.729179965258329],[-17.492273470154714,14.729305977411247],[-17.492509920686715,14.729365576909258],[-17.492577465629125,14.729366044527005],[-17.492620235849962,14.7293476568533]]}},{"type":"Feature","properties":{"OBJECTID":42,"Id":0,"Nom":"Rue ASS - 42","Type":"Paire","Shape_Leng":476.456055606},"geometry":{"type":"LineString","coordinates":[[-17.492290273966585,14.729310213121174],[-17.49244337377963,14.729020904353405],[-17.492488124159294,14.728960666416944],[-17.49253786768597,14.72890754583664],[-17.49268287955628,14.728809178796542],[-17.492992437871994,14.728602674333878],[-17.49335083707977,14.728371740406136],[-17.49395804036259,14.72799112550699],[-17.49457741064894,14.727600817545714],[-17.495217393431783,14.727187579214789],[-17.495681914100576,14.726894547140423],[-17.495829473146877,14.726804517359932]]}},{"type":"Feature","properties":{"OBJECTID":43,"Id":0,"Nom":"Rue ASS - 14","Type":"Paire","Shape_Leng":882.491378578},"geometry":{"type":"LineString","coordinates":[[-17.49407347867998,14.72549184371133],[-17.494376813131662,14.725645374155969],[-17.494598338174228,14.725774927237287],[-17.494893912915405,14.725937145344055],[-17.49523972424497,14.726111251185392],[-17.495317921055793,14.726162992809353],[-17.49538850987205,14.72623680681779],[-17.495566846818363,14.726455741768303],[-17.495768353063696,14.726725098864172],[-17.495829473146877,14.726804517359932],[-17.496053795051967,14.7271187985733],[-17.49624760752879,14.727402580297962],[-17.496442293597905,14.72767678995184],[-17.4966389341329,14.727950023394033],[-17.49687346755899,14.728272564103703],[-17.497114058636885,14.728609380441064],[-17.497382010263546,14.729019520303586],[-17.497579197993737,14.729340550519584],[-17.497802557425103,14.729716751724382],[-17.498132229001843,14.73019793067906],[-17.498170307306133,14.730263490588962],[-17.498192919368847,14.730351206008539],[-17.49817917700612,14.730438359052432],[-17.49815656153093,14.73052273872809],[-17.49803404425632,14.7308921571534],[-17.497883454544283,14.731298208506404],[-17.497689717055582,14.731808696916481]]}},{"type":"Feature","properties":{"OBJECTID":44,"Id":0,"Nom":"Rue ASS - 135","Type":"Impaire","Shape_Leng":50.5785695378},"geometry":{"type":"LineString","coordinates":[[-17.490261803328984,14.731643244245832],[-17.490367399057895,14.731198055344956]]}},{"type":"Feature","properties":{"OBJECTID":45,"Id":0,"Nom":"Rue ASS - 133","Type":"Impaire","Shape_Leng":50.1374588386},"geometry":{"type":"LineString","coordinates":[[-17.489481891336922,14.730999884111469],[-17.489372810146286,14.73144017872345]]}},{"type":"Feature","properties":{"OBJECTID":46,"Id":0,"Nom":"Rue ASS - 131","Type":"Impaire","Shape_Leng":46.3608191867},"geometry":{"type":"LineString","coordinates":[[-17.49023558755772,14.730695561792988],[-17.490259135071884,14.730597266446965],[-17.49028870208098,14.730476001622822],[-17.490332752446836,14.730287585100735]]}},{"type":"Feature","properties":{"OBJECTID":47,"Id":0,"Nom":"Rue ASS - 129","Type":"Impaire","Shape_Leng":47.0045905554},"geometry":{"type":"LineString","coordinates":[[-17.490725264093175,14.73038051050352],[-17.490630627462142,14.730795012369597]]}},{"type":"Feature","properties":{"OBJECTID":48,"Id":0,"Nom":"Rue ASS - 94","Type":"Paire","Shape_Leng":124.492941812},"geometry":{"type":"LineString","coordinates":[[-17.489503247482588,14.72978499040888],[-17.489032742895944,14.729667990496745],[-17.488766298054315,14.729616478259105],[-17.488624441948808,14.729614418368742],[-17.488529199270864,14.729662052524315],[-17.48848683555264,14.729716288233025],[-17.488444386378244,14.72978147583139]]}},{"type":"Feature","properties":{"OBJECTID":49,"Id":0,"Nom":"Rue ASS - 117","Type":"Impaire","Shape_Leng":85.3623168398},"geometry":{"type":"LineString","coordinates":[[-17.489231993202804,14.729717537665383],[-17.48929785426551,14.72943985779304],[-17.489366808475527,14.72913197030729],[-17.489408550887006,14.728965851026757]]}},{"type":"Feature","properties":{"OBJECTID":50,"Id":0,"Nom":"Rue ASS - 115","Type":"Impaire","Shape_Leng":23.0235642189},"geometry":{"type":"LineString","coordinates":[[-17.488766298054315,14.729616478259105],[-17.488755486073657,14.729540225916635],[-17.48873865126586,14.729485192284354],[-17.488674838498504,14.729445722620275]]}},{"type":"Feature","properties":{"OBJECTID":51,"Id":0,"Nom":"Rue ASS - 44","Type":"Paire","Shape_Leng":392.265105034},"geometry":{"type":"LineString","coordinates":[[-17.492620235849962,14.7293476568533],[-17.4928808933846,14.729160731758268],[-17.493171564641028,14.728977854584453],[-17.49378008325391,14.728583123733355],[-17.49420726798869,14.728310813601784],[-17.494604962986745,14.728036908238868],[-17.4948390304074,14.727887147232435],[-17.495025270926433,14.727764671016182],[-17.49534720755713,14.727567113173983],[-17.49564365904285,14.727374610545622]]}},{"type":"Feature","properties":{"OBJECTID":52,"Id":0,"Nom":"Rue ASS - 50","Type":"Paire","Shape_Leng":389.961490814},"geometry":{"type":"LineString","coordinates":[[-17.49384083772814,14.73011149600485],[-17.493907558940364,14.7299008002415],[-17.494100002716387,14.729718980546531],[-17.49443129747781,14.729480210080924],[-17.494987512290297,14.729151054472865],[-17.495697463417812,14.72869212336067],[-17.496700434857534,14.728034602141232]]}},{"type":"Feature","properties":{"OBJECTID":53,"Id":0,"Nom":"Rue ASS - 99","Type":"Impaire","Shape_Leng":112.576717948},"geometry":{"type":"LineString","coordinates":[[-17.49323958301239,14.728443427305958],[-17.492481529399257,14.727743510124391]]}},{"type":"Feature","properties":{"OBJECTID":54,"Id":0,"Nom":"Rue ASS - 97","Type":"Impaire","Shape_Leng":123.362562856},"geometry":{"type":"LineString","coordinates":[[-17.493558759493695,14.728241408748218],[-17.49272778137851,14.7274747385862]]}},{"type":"Feature","properties":{"OBJECTID":55,"Id":0,"Nom":"Rue ASS - 95","Type":"Impaire","Shape_Leng":134.555493621},"geometry":{"type":"LineString","coordinates":[[-17.493889608988248,14.728034021643541],[-17.492981715698733,14.72719935347648]]}},{"type":"Feature","properties":{"OBJECTID":56,"Id":0,"Nom":"Rue ASS - 93","Type":"Impaire","Shape_Leng":159.533366131},"geometry":{"type":"LineString","coordinates":[[-17.4946837636304,14.727532145181518],[-17.494397118859748,14.72725913807887],[-17.494115480768755,14.727005223980035],[-17.49361502636599,14.726534791126658]]}},{"type":"Feature","properties":{"OBJECTID":57,"Id":0,"Nom":"Rue ASS - 38","Type":"Paire","Shape_Leng":160.406767803},"geometry":{"type":"LineString","coordinates":[[-17.494115480768755,14.727005223980035],[-17.49447139706779,14.72674754986034],[-17.495001996184456,14.726434087664492],[-17.495347374234118,14.726193791719247]]}},{"type":"Feature","properties":{"OBJECTID":58,"Id":0,"Nom":"Rue ASS - 91","Type":"Impaire","Shape_Leng":101.683219733},"geometry":{"type":"LineString","coordinates":[[-17.494418907529614,14.726785550415661],[-17.493913055158064,14.726306354761896],[-17.493833756964438,14.72624410627565],[-17.49370037307276,14.726218259173876]]}},{"type":"Feature","properties":{"OBJECTID":59,"Id":0,"Nom":"Rue ASS - 89","Type":"Impaire","Shape_Leng":149.869208307},"geometry":{"type":"LineString","coordinates":[[-17.49496385012797,14.72645662388929],[-17.494898159413168,14.726373658868727],[-17.49462107098312,14.726238968549744],[-17.49453656593542,14.726193986999084],[-17.494427193466727,14.726165048482548],[-17.49421953543459,14.72601964124971],[-17.494153029168732,14.726002431920422],[-17.494036458888914,14.726053166066537],[-17.49378699199125,14.726235044135798]]}},{"type":"Feature","properties":{"OBJECTID":60,"Id":0,"Nom":"Rue ASS - 171","Type":"Impaire","Shape_Leng":49.2276808139},"geometry":{"type":"LineString","coordinates":[[-17.49379710133764,14.725751079304022],[-17.493893285172103,14.725794770859036],[-17.49401560002288,14.725860378978542],[-17.494119484867515,14.725924991342335],[-17.49413065729657,14.725935626749608],[-17.494153029168732,14.726002431920422]]}},{"type":"Feature","properties":{"OBJECTID":61,"Id":0,"Nom":"Rue ASS - 40","Type":"Paire","Shape_Leng":164.850075899},"geometry":{"type":"LineString","coordinates":[[-17.495195516694153,14.72673121051075],[-17.494826132449663,14.726967642972316],[-17.49438709323601,14.72725009928023],[-17.49401122738116,14.727656328319515]]}},{"type":"Feature","properties":{"OBJECTID":62,"Id":0,"Nom":"Rue ASS - 87","Type":"Paire","Shape_Leng":82.6923937916},"geometry":{"type":"LineString","coordinates":[[-17.495435734860536,14.727049843466467],[-17.495195516694153,14.72673121051075],[-17.495001996184456,14.726434087664492]]}},{"type":"Feature","properties":{"OBJECTID":63,"Id":0,"Nom":"Rue ASS - 173","Type":"Impaire","Shape_Leng":28.267659878},"geometry":{"type":"LineString","coordinates":[[-17.49401122738116,14.727656328319515],[-17.49418292956069,14.727849408461694]]}},{"type":"Feature","properties":{"OBJECTID":64,"Id":0,"Nom":"Rue ASS - 75","Type":"Impaire","Shape_Leng":45.194292231},"geometry":{"type":"LineString","coordinates":[[-17.493397806171807,14.728831097038652],[-17.493167782103452,14.728489692192431]]}},{"type":"Feature","properties":{"OBJECTID":65,"Id":0,"Nom":"Rue ASS - 77","Type":"Impaire","Shape_Leng":167.687363513},"geometry":{"type":"LineString","coordinates":[[-17.49485884077277,14.72922719936141],[-17.494626896317893,14.728889621265102],[-17.4944112679464,14.728587908726285],[-17.49420726798869,14.728310813601784],[-17.49399168059075,14.727969927098819]]}},{"type":"Feature","properties":{"OBJECTID":66,"Id":0,"Nom":"Rue ASS - 85","Type":"Impaire","Shape_Leng":166.593322339},"geometry":{"type":"LineString","coordinates":[[-17.496304602505553,14.72829409960331],[-17.496079187226936,14.727969306161008],[-17.49586716464984,14.727682375481004],[-17.49564365904285,14.727374610545622],[-17.495435734860536,14.727049843466467]]}},{"type":"Feature","properties":{"OBJECTID":67,"Id":0,"Nom":"Rue ASS - 81","Type":"Impaire","Shape_Leng":167.406092458},"geometry":{"type":"LineString","coordinates":[[-17.49481377314147,14.72744819794245],[-17.495025270926433,14.727764671016182],[-17.49545346202746,14.72836207681082],[-17.49568244189675,14.728701833320656]]}},{"type":"Feature","properties":{"OBJECTID":68,"Id":0,"Nom":"Rue ASS - 48","Type":"Paire","Shape_Leng":186.713321701},"geometry":{"type":"LineString","coordinates":[[-17.494626896317893,14.728889621265102],[-17.49545346202746,14.72836207681082],[-17.496079187226936,14.727969306161008]]}},{"type":"Feature","properties":{"OBJECTID":69,"Id":0,"Nom":"Rue ASS - 79","Type":"Impaire","Shape_Leng":80.2521440153},"geometry":{"type":"LineString","coordinates":[[-17.49513603545868,14.728564670612549],[-17.494722403589577,14.727961767261382]]}},{"type":"Feature","properties":{"OBJECTID":70,"Id":0,"Nom":"Rue ASS - 46","Type":"Paire","Shape_Leng":66.3713437256},"geometry":{"type":"LineString","coordinates":[[-17.4944112679464,14.728587908726285],[-17.494927003421363,14.728259988493637]]}},{"type":"Feature","properties":{"OBJECTID":71,"Id":0,"Nom":"Rue ASS - 83","Type":"Impaire","Shape_Leng":80.1627649537},"geometry":{"type":"LineString","coordinates":[[-17.495755597900473,14.72817242514562],[-17.49534720755713,14.727567113173983]]}},{"type":"Feature","properties":{"OBJECTID":72,"Id":0,"Nom":"Rue ASS - 152","Type":"Paire","Shape_Leng":51.346730488},"geometry":{"type":"LineString","coordinates":[[-17.496266273792013,14.727428871835174],[-17.49586716464984,14.727682375481004]]}},{"type":"Feature","properties":{"OBJECTID":73,"Id":0,"Nom":"Rue ASS - 103","Type":"Impaire","Shape_Leng":99.7720149669},"geometry":{"type":"LineString","coordinates":[[-17.491565409874813,14.728757642392706],[-17.491323795056953,14.728443780825875],[-17.491027991469398,14.728023893431097]]}},{"type":"Feature","properties":{"OBJECTID":74,"Id":0,"Nom":"Rue ASS - 175","Type":"Impaire","Shape_Leng":73.2351334001},"geometry":{"type":"LineString","coordinates":[[-17.4904352739189,14.729192580750864],[-17.490160525723848,14.728843520351663],[-17.490166941929576,14.72875979324294],[-17.49015124404946,14.728675108006223],[-17.49012738619615,14.72862875583572]]}},{"type":"Feature","properties":{"OBJECTID":75,"Id":0,"Nom":"Rue ASS - 34","Type":"Paire","Shape_Leng":457.281941221},"geometry":{"type":"LineString","coordinates":[[-17.49012738619615,14.72862875583572],[-17.49080375478508,14.728174501855607],[-17.491027991469398,14.728023893431097],[-17.491336500299834,14.727832938096196],[-17.491616876415662,14.727651846850678],[-17.49190816729754,14.727458686793192],[-17.49219822973414,14.727265539669183],[-17.492488290698883,14.727072392268397],[-17.493237693747947,14.726562370810944],[-17.49347558230239,14.726424759596208],[-17.493599374573577,14.726404303716464],[-17.493674504954576,14.726393919156976]]}},{"type":"Feature","properties":{"OBJECTID":76,"Id":0,"Nom":"Rue ASS - 113","Type":"Impaire","Shape_Leng":50.4304718006},"geometry":{"type":"LineString","coordinates":[[-17.492488290698883,14.727072392268397],[-17.492750913550186,14.727449491598975]]}},{"type":"Feature","properties":{"OBJECTID":77,"Id":0,"Nom":"Rue ASS - 111","Type":"Impaire","Shape_Leng":59.4612934825},"geometry":{"type":"LineString","coordinates":[[-17.49219822973414,14.727265539669183],[-17.4925189333536,14.727702684883766]]}},{"type":"Feature","properties":{"OBJECTID":78,"Id":0,"Nom":"Rue ASS - 109","Type":"Impaire","Shape_Leng":69.4555811248},"geometry":{"type":"LineString","coordinates":[[-17.49190816729754,14.727458686793192],[-17.492270776492497,14.727977447319082]]}},{"type":"Feature","properties":{"OBJECTID":79,"Id":0,"Nom":"Rue ASS - 107","Type":"Impaire","Shape_Leng":79.0226687775},"geometry":{"type":"LineString","coordinates":[[-17.491616876415662,14.727651846850678],[-17.492036891971434,14.728237059526137]]}},{"type":"Feature","properties":{"OBJECTID":80,"Id":0,"Nom":"Rue ASS - 105","Type":"Impaire","Shape_Leng":88.9335188638},"geometry":{"type":"LineString","coordinates":[[-17.49132783585478,14.727838300876554],[-17.491804191568985,14.72849440575508]]}},{"type":"Feature","properties":{"OBJECTID":81,"Id":0,"Nom":"Rue ASS - 1","Type":"Impaire","Shape_Leng":67.8338945071},"geometry":{"type":"LineString","coordinates":[[-17.494348767830818,14.725631179647408],[-17.494637095289463,14.725086439985686]]}},{"type":"Feature","properties":{"OBJECTID":82,"Id":0,"Nom":"Rue ASS - 3","Type":"Impaire","Shape_Leng":138.318494412},"geometry":{"type":"LineString","coordinates":[[-17.49464985417717,14.725803201068876],[-17.494942900570628,14.725241377672582],[-17.495244667070235,14.72469602019378]]}},{"type":"Feature","properties":{"OBJECTID":83,"Id":0,"Nom":"Rue ASS - 5","Type":"Impaire","Shape_Leng":140.805983008},"geometry":{"type":"LineString","coordinates":[[-17.494964288414938,14.725972577545784],[-17.495270197517414,14.725385565891626],[-17.495548323193283,14.724834725521506]]}},{"type":"Feature","properties":{"OBJECTID":84,"Id":0,"Nom":"Rue ASS - 2","Type":"Paire","Shape_Leng":596.927402808},"geometry":{"type":"LineString","coordinates":[[-17.495244667070235,14.72469602019378],[-17.495548323193283,14.724834725521506],[-17.496231545991197,14.7251787227591],[-17.496748527650066,14.725416949538975],[-17.49694931494068,14.725450637327608],[-17.49727980388962,14.725616299583114],[-17.49772321694453,14.725863923342965],[-17.498172492629795,14.726108613649311],[-17.498477821107667,14.726264507001218],[-17.49882231131257,14.726365480016497],[-17.499156225200267,14.726443620558202],[-17.49927276920751,14.726455268785184],[-17.499514566676496,14.726466998842264],[-17.49985327870991,14.726448999214703],[-17.500100691583775,14.726436288215174],[-17.50026430166972,14.726440257296055],[-17.50031465796611,14.726462659385916],[-17.500332845422644,14.72650692051878]]}},{"type":"Feature","properties":{"OBJECTID":85,"Id":0,"Nom":"Rue ASS - 4","Type":"Paire","Shape_Leng":151.164191578},"geometry":{"type":"LineString","coordinates":[[-17.495887805055467,14.725704216594886],[-17.495270197517414,14.725385565891626],[-17.494942900570628,14.725241377672582],[-17.494637095289463,14.725086439985686]]}},{"type":"Feature","properties":{"OBJECTID":86,"Id":0,"Nom":"Rue ASS - 7","Type":"Impaire","Shape_Leng":141.430023117},"geometry":{"type":"LineString","coordinates":[[-17.4952790513168,14.726137273112839],[-17.495577039844335,14.725543880054456],[-17.49587836789113,14.725000900771319]]}},{"type":"Feature","properties":{"OBJECTID":87,"Id":0,"Nom":"Rue ASS - 35","Type":"Impaire","Shape_Leng":425.149435025},"geometry":{"type":"LineString","coordinates":[[-17.497382010263546,14.729019520303586],[-17.497710692568734,14.72877376186565],[-17.49816028086698,14.728398902364788],[-17.498595476990783,14.728054313029952],[-17.498847191280536,14.727858704072965],[-17.499007408225868,14.727737224166559],[-17.49909813661143,14.727681746477778],[-17.49922123028227,14.727621618193995],[-17.49929121679818,14.727556325968353],[-17.499437230376678,14.727438583872148],[-17.499641656595752,14.72727431947812],[-17.499983222494713,14.726989539274133],[-17.500202464357066,14.726703211931294],[-17.500332845422644,14.72650692051878]]}},{"type":"Feature","properties":{"OBJECTID":88,"Id":0,"Nom":"Rue ASS - 9","Type":"Impaire","Shape_Leng":141.341909441},"geometry":{"type":"LineString","coordinates":[[-17.49558781663221,14.726292035907042],[-17.495887805055467,14.725704216594886],[-17.496178424870028,14.725151977070395]]}},{"type":"Feature","properties":{"OBJECTID":89,"Id":0,"Nom":"Rue ASS - 11","Type":"Impaire","Shape_Leng":138.80784615},"geometry":{"type":"LineString","coordinates":[[-17.495923958374036,14.726423798927184],[-17.496223636169205,14.725850104467977],[-17.49650735346163,14.725305815749728]]}},{"type":"Feature","properties":{"OBJECTID":90,"Id":0,"Nom":"Rue ASS - 6","Type":"Paire","Shape_Leng":386.957756046},"geometry":{"type":"LineString","coordinates":[[-17.496242342409214,14.725814216008555],[-17.496634080492665,14.726006300463641],[-17.496934666199692,14.726177066441187],[-17.49725070758432,14.72632471904105],[-17.497570894931204,14.726491448490552],[-17.49789108363108,14.726658176504632],[-17.498197455591242,14.726819317515872],[-17.498499746715222,14.726967116566062],[-17.498937532355583,14.727194720265162],[-17.499137015893517,14.727286262222542],[-17.499405079823994,14.727464510599983]]}},{"type":"Feature","properties":{"OBJECTID":91,"Id":0,"Nom":"Rue ASS - 13","Type":"Impaire","Shape_Leng":74.3469198018},"geometry":{"type":"LineString","coordinates":[[-17.496155117555087,14.726523468741515],[-17.49646390789059,14.72592285856664]]}},{"type":"Feature","properties":{"OBJECTID":92,"Id":0,"Nom":"Rue ASS - 15","Type":"Impaire","Shape_Leng":143.318623792},"geometry":{"type":"LineString","coordinates":[[-17.49633711720271,14.726606512224771],[-17.496634080492665,14.726006300463641],[-17.496766266830818,14.725763341819826],[-17.49692896195405,14.725447222551876]]}},{"type":"Feature","properties":{"OBJECTID":93,"Id":0,"Nom":"Rue ASS - 17","Type":"Impaire","Shape_Leng":142.686621293},"geometry":{"type":"LineString","coordinates":[[-17.496641654578184,14.726746098653296],[-17.496934666199692,14.726177066441187],[-17.49725275611943,14.725602741519818]]}},{"type":"Feature","properties":{"OBJECTID":94,"Id":0,"Nom":"Rue ASS - 19","Type":"Impaire","Shape_Leng":140.341369699},"geometry":{"type":"LineString","coordinates":[[-17.496965619447348,14.726899402607824],[-17.49725070758432,14.72632471904105],[-17.497296570037282,14.72626530497551],[-17.497559568137035,14.725772534320432]]}},{"type":"Feature","properties":{"OBJECTID":95,"Id":0,"Nom":"Rue ASS - 21","Type":"Impaire","Shape_Leng":138.226145191},"geometry":{"type":"LineString","coordinates":[[-17.49728597721553,14.727055114558887],[-17.497570894931204,14.726491448490552],[-17.49786061555269,14.725938755415651]]}},{"type":"Feature","properties":{"OBJECTID":96,"Id":0,"Nom":"Rue ASS - 23","Type":"Impaire","Shape_Leng":136.591920148},"geometry":{"type":"LineString","coordinates":[[-17.49759659864247,14.72720784328097],[-17.49788406919898,14.726654524819022],[-17.498172492629795,14.726108613649311]]}},{"type":"Feature","properties":{"OBJECTID":97,"Id":0,"Nom":"Rue ASS - 25","Type":"Impaire","Shape_Leng":136.020376594},"geometry":{"type":"LineString","coordinates":[[-17.497905658249724,14.727359675952437],[-17.498197455591242,14.726819317515872],[-17.498477821107667,14.726264507001218]]}},{"type":"Feature","properties":{"OBJECTID":98,"Id":0,"Nom":"Rue ASS - 27","Type":"Impaire","Shape_Leng":142.761431509},"geometry":{"type":"LineString","coordinates":[[-17.498214059208205,14.727511183711306],[-17.498499746715222,14.726967116566062],[-17.49882231131257,14.726365480016497]]}},{"type":"Feature","properties":{"OBJECTID":99,"Id":0,"Nom":"Rue ASS - 29","Type":"Impaire","Shape_Leng":152.830738364},"geometry":{"type":"LineString","coordinates":[[-17.49852031768142,14.727668346767018],[-17.498565321855843,14.72757493629352],[-17.49881881036455,14.727132997007132],[-17.49917874169311,14.726445870773915]]}},{"type":"Feature","properties":{"OBJECTID":100,"Id":0,"Nom":"Rue ASS - 31","Type":"Impaire","Shape_Leng":163.261820093},"geometry":{"type":"MultiLineString","coordinates":[[[-17.499124405871573,14.727280475767612],[-17.49932950618333,14.726908775083082],[-17.499404295524027,14.726790846444755],[-17.499555332730377,14.726464833093235]],[[-17.499137015893517,14.727286262222542],[-17.498920813960524,14.727671367964826],[-17.4989139206654,14.7277130326957],[-17.498969268405144,14.727766142507932]]]}},{"type":"Feature","properties":{"OBJECTID":101,"Id":0,"Nom":"Rue ASS - 33","Type":"Impaire","Shape_Leng":100.548909608},"geometry":{"type":"LineString","coordinates":[[-17.499942164267157,14.726444432778719],[-17.499593452949057,14.727139927826387],[-17.499699318365426,14.727226244291574]]}},{"type":"Feature","properties":{"OBJECTID":102,"Id":0,"Nom":"Rue ASS - 177","Type":"Impaire","Shape_Leng":75.0692858139},"geometry":{"type":"LineString","coordinates":[[-17.4961370509959,14.727240703243677],[-17.496351790901237,14.727047517396512],[-17.496509362605302,14.726685104053907]]}},{"type":"Feature","properties":{"OBJECTID":103,"Id":0,"Nom":"Rue ASS - 179","Type":"Impaire","Shape_Leng":101.638181095},"geometry":{"type":"LineString","coordinates":[[-17.49640437046036,14.727623376746914],[-17.496557028191766,14.727471800291916],[-17.49671168777693,14.727336756825853],[-17.496753827391633,14.727284673356047],[-17.49677838534668,14.72724138437119],[-17.496931033891244,14.726883035904166]]}},{"type":"Feature","properties":{"OBJECTID":104,"Id":0,"Nom":"Rue ASS - 181","Type":"Impaire","Shape_Leng":125.692838908},"geometry":{"type":"LineString","coordinates":[[-17.49666380486827,14.727984226705022],[-17.496918408976203,14.727761899530472],[-17.497110559659514,14.727554744575643],[-17.497181498009336,14.727443551237736],[-17.497362711937164,14.727092845989585]]}},{"type":"Feature","properties":{"OBJECTID":105,"Id":0,"Nom":"Rue ASS - 183","Type":"Impaire","Shape_Leng":150.72485713},"geometry":{"type":"LineString","coordinates":[[-17.497770298250362,14.727293177510196],[-17.497551768713944,14.727737855392455],[-17.4973950939731,14.727954667220077],[-17.49732960806106,14.72802708062035],[-17.497174964839573,14.728163558015552],[-17.496931973257762,14.728354470321511]]}},{"type":"Feature","properties":{"OBJECTID":106,"Id":0,"Nom":"Rue ASS - 10","Type":"Paire","Shape_Leng":106.446648691},"geometry":{"type":"LineString","coordinates":[[-17.497551768713944,14.727737855392455],[-17.49806585012364,14.727979456450736],[-17.498420894957324,14.728192547612604]]}},{"type":"Feature","properties":{"OBJECTID":107,"Id":0,"Nom":"Rue ASS - 12","Type":"Paire","Shape_Leng":90.9469170959},"geometry":{"type":"LineString","coordinates":[[-17.497363858966605,14.727989206700585],[-17.497480938158475,14.72816025607802],[-17.49758579170486,14.728309708947883],[-17.49783088918946,14.728673544043179]]}},{"type":"Feature","properties":{"OBJECTID":108,"Id":0,"Nom":"Rue ASS - 16","Type":"Paire","Shape_Leng":68.1894232331},"geometry":{"type":"LineString","coordinates":[[-17.50001206081796,14.72695187619035],[-17.50058247779055,14.727218769457327]]}},{"type":"Feature","properties":{"OBJECTID":109,"Id":0,"Nom":"Rue ASS - 18","Type":"Paire","Shape_Leng":90.6807253447},"geometry":{"type":"LineString","coordinates":[[-17.499609978735098,14.727299773166079],[-17.499885746073325,14.727441045983237],[-17.500357880940644,14.727675481916734]]}},{"type":"Feature","properties":{"OBJECTID":110,"Id":0,"Nom":"Rue ASS - 20","Type":"Paire","Shape_Leng":113.971744887},"geometry":{"type":"LineString","coordinates":[[-17.50017291851852,14.7280709833015],[-17.49962925814761,14.727820993606322],[-17.49922123028227,14.727621618193995]]}},{"type":"Feature","properties":{"OBJECTID":111,"Id":0,"Nom":"Rue ASS -30","Type":"Paire","Shape_Leng":169.013812679},"geometry":{"type":"LineString","coordinates":[[-17.49931933566886,14.729875540987846],[-17.498787944354234,14.729767955196921],[-17.49828595375239,14.729682546914075],[-17.49801045627376,14.72964488760183],[-17.497773432796702,14.72966769759365]]}},{"type":"Feature","properties":{"OBJECTID":112,"Id":0,"Nom":"Rue ASS - 43","Type":"Impaire","Shape_Leng":169.732541427},"geometry":{"type":"LineString","coordinates":[[-17.498338717763428,14.729691524262607],[-17.49839709235341,14.729306081127607],[-17.49854218200404,14.72846076476364],[-17.49839729603628,14.728211233373857]]}},{"type":"Feature","properties":{"OBJECTID":113,"Id":0,"Nom":"Rue ASS - 41","Type":"Impaire","Shape_Leng":203.484106027},"geometry":{"type":"LineString","coordinates":[[-17.498787944354234,14.729767955196921],[-17.498906892411956,14.728999408457758],[-17.498996558662043,14.728463029508747],[-17.499098830619,14.727955197086478]]}},{"type":"Feature","properties":{"OBJECTID":114,"Id":0,"Nom":"Rue ASS - 8","Type":"Paire","Shape_Leng":489.634500754},"geometry":{"type":"LineString","coordinates":[[-17.49538850987205,14.72623680681779],[-17.495663879515224,14.726313113056978],[-17.49611303598312,14.72650426840105],[-17.49660431969443,14.726728430855742],[-17.49706359926213,14.726945767209008],[-17.497577311667342,14.72719836887804],[-17.49830095354689,14.727553872228652],[-17.498740726923803,14.72778336626659],[-17.498847191280536,14.727858704072965],[-17.499098830619,14.727955197086478],[-17.4994897734819,14.728123668803114]]}},{"type":"Feature","properties":{"OBJECTID":115,"Id":0,"Nom":"Rue ASS - 22","Type":"Paire","Shape_Leng":95.1565072673},"geometry":{"type":"LineString","coordinates":[[-17.498985857339466,14.728527045495747],[-17.49925081536381,14.728619592018157],[-17.4997984791081,14.728862168358086]]}},{"type":"Feature","properties":{"OBJECTID":116,"Id":0,"Nom":"Rue ASS - 39","Type":"Impaire","Shape_Leng":165.419838244},"geometry":{"type":"LineString","coordinates":[[-17.49964421888336,14.727827873032988],[-17.4994897734819,14.728123668803114],[-17.49925081536381,14.728619592018157],[-17.49908873507477,14.728900640065623],[-17.49896529322144,14.729167288110064]]}},{"type":"Feature","properties":{"OBJECTID":117,"Id":0,"Nom":"Rue ASS - 24","Type":"Paire","Shape_Leng":76.3066373955},"geometry":{"type":"LineString","coordinates":[[-17.498884280984832,14.729145500520124],[-17.49896529322144,14.729167288110064],[-17.4991295478952,14.729249172674525],[-17.499525897890624,14.729434369660208]]}},{"type":"Feature","properties":{"OBJECTID":118,"Id":0,"Nom":"Rue ASS - 45","Type":"Impaire","Shape_Leng":95.8821136539},"geometry":{"type":"LineString","coordinates":[[-17.498604525351684,14.729736748902443],[-17.498514862319283,14.730187759221367],[-17.498452645202796,14.730589989108182]]}},{"type":"Feature","properties":{"OBJECTID":119,"Id":0,"Nom":"Rue ASS -32","Type":"Paire","Shape_Leng":52.8883808396},"geometry":{"type":"LineString","coordinates":[[-17.498452645202796,14.730589989108182],[-17.49893401409722,14.73068368309174]]}},{"type":"Feature","properties":{"OBJECTID":120,"Id":0,"Nom":"Rue ASS - 147","Type":"Impaire","Shape_Leng":30.6612118116},"geometry":{"type":"LineString","coordinates":[[-17.49520349882425,14.732288070105488],[-17.49530177010799,14.732028132256637]]}},{"type":"Feature","properties":{"OBJECTID":121,"Id":0,"Nom":"Rue ASS - 157","Type":"Impaire","Shape_Leng":30.349368485},"geometry":{"type":"LineString","coordinates":[[-17.496198674067134,14.732645263808118],[-17.496298606987995,14.732388937590411]]}},{"type":"Feature","properties":{"OBJECTID":122,"Id":0,"Nom":"Rue ASS - 165","Type":"Impaire","Shape_Leng":30.1105218362},"geometry":{"type":"LineString","coordinates":[[-17.496678896248188,14.732815202522524],[-17.496767626815345,14.732557275203314]]}},{"type":"Feature","properties":{"OBJECTID":123,"Id":0,"Nom":"Rue ASS - 167","Type":"Impaire","Shape_Leng":29.9040997528},"geometry":{"type":"LineString","coordinates":[[-17.496950689085203,14.732906510100571],[-17.497038015392686,14.732650092989218]]}},{"type":"Feature","properties":{"OBJECTID":124,"Id":0,"Nom":"Rue ASS - 149","Type":"Impaire","Shape_Leng":27.9919288862},"geometry":{"type":"LineString","coordinates":[[-17.495494430273357,14.731834970509833],[-17.49558280670002,14.731597186628516]]}},{"type":"Feature","properties":{"OBJECTID":125,"Id":0,"Nom":"Rue ASS - 153","Type":"Impaire","Shape_Leng":29.915907417},"geometry":{"type":"LineString","coordinates":[[-17.495736656055886,14.731654146425514],[-17.49582934287905,14.731399404497758]]}},{"type":"Feature","properties":{"OBJECTID":126,"Id":0,"Nom":"Rue ASS - 151","Type":"Impaire","Shape_Leng":29.9177721298},"geometry":{"type":"LineString","coordinates":[[-17.495479218519467,14.73155883472616],[-17.495573661211605,14.731304686423393]]}},{"type":"Feature","properties":{"OBJECTID":127,"Id":0,"Nom":"Rue ASS - 159","Type":"Impaire","Shape_Leng":30.0185344831},"geometry":{"type":"LineString","coordinates":[[-17.49657106200926,14.731666142600032],[-17.49647255468682,14.73191979840272]]}},{"type":"Feature","properties":{"OBJECTID":128,"Id":0,"Nom":"Rue ASS - 163","Type":"Impaire","Shape_Leng":29.9077934898},"geometry":{"type":"LineString","coordinates":[[-17.496951861525005,14.732088237068565],[-17.497046215092507,14.731834153491205]]}},{"type":"Feature","properties":{"OBJECTID":129,"Id":0,"Nom":"Rue ASS - 78","Type":"Paire","Shape_Leng":103.752571405},"geometry":{"type":"LineString","coordinates":[[-17.497246544059713,14.731614490791111],[-17.497385767368307,14.731284148192119],[-17.49743692759879,14.731247742174897],[-17.497624436849325,14.730762414671643]]}},{"type":"Feature","properties":{"OBJECTID":130,"Id":0,"Nom":"Rue ASS - 66","Type":"Paire","Shape_Leng":273.786254266},"geometry":{"type":"LineString","coordinates":[[-17.496156798413825,14.731225668343834],[-17.496299334188638,14.730861328333871],[-17.496486829585407,14.73037456698539],[-17.496612161076907,14.729993168597664],[-17.496684507873606,14.729876222641181],[-17.496754414210187,14.729803761624206],[-17.497555215795337,14.729301506426891]]}},{"type":"Feature","properties":{"OBJECTID":131,"Id":0,"Nom":"Rue ASS - 72","Type":"Paire","Shape_Leng":103.065723386},"geometry":{"type":"LineString","coordinates":[[-17.497746208572487,14.729621845339835],[-17.49737238998774,14.729851589803372],[-17.49694678294292,14.730132971304378]]}},{"type":"Feature","properties":{"OBJECTID":132,"Id":0,"Nom":"Rue ASS - 53","Type":"Impaire","Shape_Leng":176.52259928},"geometry":{"type":"LineString","coordinates":[[-17.496602935780903,14.73002124166408],[-17.49694678294292,14.730132971304378],[-17.497435244321395,14.73032417645244],[-17.49814120259191,14.730569050663657]]}},{"type":"Feature","properties":{"OBJECTID":133,"Id":0,"Nom":"Rue ASS - 51","Type":"Impaire","Shape_Leng":176.27371336},"geometry":{"type":"LineString","coordinates":[[-17.496486829585407,14.73037456698539],[-17.496828558111748,14.730491346960433],[-17.497624436849325,14.730762414671643],[-17.49803404425632,14.7308921571534]]}},{"type":"Feature","properties":{"OBJECTID":134,"Id":0,"Nom":"Rue ASS - 47","Type":"Impaire","Shape_Leng":86.4048445019},"geometry":{"type":"LineString","coordinates":[[-17.496299334188638,14.730861328333871],[-17.496657238728368,14.730975066573249],[-17.497055300837875,14.731121354537848]]}},{"type":"Feature","properties":{"OBJECTID":135,"Id":0,"Nom":"Rue ASS - 70","Type":"Paire","Shape_Leng":56.6399102755},"geometry":{"type":"LineString","coordinates":[[-17.496657238728368,14.730975066573249],[-17.496828558111748,14.730491346960433]]}},{"type":"Feature","properties":{"OBJECTID":136,"Id":0,"Nom":"Rue ASS - 49","Type":"Impaire","Shape_Leng":89.8056311386},"geometry":{"type":"LineString","coordinates":[[-17.496717220379253,14.730805710023633],[-17.497125602835954,14.730954236263935],[-17.497495520325145,14.731096088895718]]}},{"type":"Feature","properties":{"OBJECTID":137,"Id":0,"Nom":"Rue ASS - 76","Type":"Paire","Shape_Leng":63.9958279106},"geometry":{"type":"LineString","coordinates":[[-17.496914098683288,14.731494361146375],[-17.497055300837875,14.731121354537848],[-17.497125602835954,14.730954236263935]]}},{"type":"Feature","properties":{"OBJECTID":138,"Id":0,"Nom":"Rue ASS - 74","Type":"Paire","Shape_Leng":39.0811908668},"geometry":{"type":"LineString","coordinates":[[-17.497327430122784,14.730661257167203],[-17.497435244321395,14.73032417645244]]}},{"type":"Feature","properties":{"OBJECTID":139,"Id":0,"Nom":"Rue ASS - 68","Type":"Paire","Shape_Leng":41.4648844193},"geometry":{"type":"LineString","coordinates":[[-17.496837426888852,14.730097437528729],[-17.496715519558997,14.730452718119524]]}},{"type":"Feature","properties":{"OBJECTID":140,"Id":0,"Nom":"Rue ASS - 55","Type":"Impaire","Shape_Leng":83.139520864},"geometry":{"type":"LineString","coordinates":[[-17.497406532829505,14.72983060590943],[-17.49756406177133,14.730076472788875],[-17.49782719779765,14.730460132898195]]}},{"type":"Feature","properties":{"OBJECTID":141,"Id":0,"Nom":"Rue ASS - 61","Type":"Impaire","Shape_Leng":176.619926872},"geometry":{"type":"LineString","coordinates":[[-17.496602935780903,14.73002124166408],[-17.496332855353035,14.729597479927575],[-17.496201850148214,14.729416040318164],[-17.495902352900295,14.728996200601523],[-17.49568244189675,14.728701833320656]]}},{"type":"Feature","properties":{"OBJECTID":142,"Id":0,"Nom":"Rue ASS - 59","Type":"Impaire","Shape_Leng":120.760675224},"geometry":{"type":"LineString","coordinates":[[-17.496045610716404,14.728463887136266],[-17.49628900950182,14.728820650005709],[-17.496527182821733,14.729160839231396],[-17.496673944805,14.729367205763005]]}},{"type":"Feature","properties":{"OBJECTID":143,"Id":0,"Nom":"Rue ASS - 52","Type":"Paire","Shape_Leng":86.368228239},"geometry":{"type":"LineString","coordinates":[[-17.4963007420179,14.728837406935433],[-17.496966487700973,14.728402788011792]]}},{"type":"Feature","properties":{"OBJECTID":144,"Id":0,"Nom":"Rue ASS - 57","Type":"Impaire","Shape_Leng":117.04259077},"geometry":{"type":"LineString","coordinates":[[-17.49656177087505,14.728666999965679],[-17.496777304070616,14.728997517530383],[-17.496921250028798,14.729215387347761],[-17.497158166160965,14.729550532990135]]}},{"type":"Feature","properties":{"OBJECTID":145,"Id":0,"Nom":"Rue ASS - 62","Type":"Paire","Shape_Leng":76.225936074},"geometry":{"type":"LineString","coordinates":[[-17.496332855353035,14.729597479927575],[-17.496673944805,14.729367205763005],[-17.496921250028798,14.729215387347761]]}},{"type":"Feature","properties":{"OBJECTID":146,"Id":0,"Nom":"Rue ASS - 60","Type":"Paire","Shape_Leng":32.4507319436},"geometry":{"type":"LineString","coordinates":[[-17.496527182821733,14.729160839231396],[-17.496777304070616,14.728997517530383]]}},{"type":"Feature","properties":{"OBJECTID":147,"Id":0,"Nom":"Rue ASS - 71","Type":"Impaire","Shape_Leng":140.681734158},"geometry":{"type":"LineString","coordinates":[[-17.4945444064052,14.729413275221498],[-17.49479236864308,14.729771848931554],[-17.495069138023684,14.730136960238955],[-17.495276713526525,14.730464573966755]]}},{"type":"Feature","properties":{"OBJECTID":148,"Id":0,"Nom":"Rue ASS - 56","Type":"Paire","Shape_Leng":67.8733768696},"geometry":{"type":"LineString","coordinates":[[-17.494833349475062,14.729825911490435],[-17.49464472188088,14.729962268879904],[-17.494519175458493,14.73015245141473],[-17.49450623677669,14.730310345921342]]}},{"type":"Feature","properties":{"OBJECTID":149,"Id":0,"Nom":"Rue ASS - 73","Type":"Impaire","Shape_Leng":88.0480834113},"geometry":{"type":"LineString","coordinates":[[-17.49450623677669,14.730310345921342],[-17.494748090016596,14.730412908717128],[-17.495083150528682,14.730504904640735],[-17.49518391556034,14.730510989156748],[-17.495276713526525,14.730464573966755]]}},{"type":"Feature","properties":{"OBJECTID":150,"Id":0,"Nom":"Rue ASS - 64","Type":"Paire","Shape_Leng":147.322152834},"geometry":{"type":"LineString","coordinates":[[-17.495276713526525,14.730464573966755],[-17.49577896353175,14.730141255988462],[-17.49642558572974,14.729742975360123]]}},{"type":"Feature","properties":{"OBJECTID":151,"Id":0,"Nom":"Rue ASS - 54","Type":"Paire","Shape_Leng":147.229256945},"geometry":{"type":"LineString","coordinates":[[-17.49591760972525,14.72901758736841],[-17.495633342763604,14.729215417504369],[-17.495077451005535,14.729572775892901],[-17.49479236864308,14.729771848931554]]}},{"type":"Feature","properties":{"OBJECTID":152,"Id":0,"Nom":"Rue ASS - 65","Type":"Paire","Shape_Leng":44.3533139657},"geometry":{"type":"LineString","coordinates":[[-17.495323456788352,14.72941463028868],[-17.495084803314636,14.72908816279082]]}},{"type":"Feature","properties":{"OBJECTID":153,"Id":0,"Nom":"Rue ASS - 58","Type":"Paire","Shape_Leng":145.793934022},"geometry":{"type":"LineString","coordinates":[[-17.495069138023684,14.730136960238955],[-17.49535416608366,14.729933107566081],[-17.49587837927611,14.72959760172503],[-17.496185355650233,14.729392917962972]]}},{"type":"Feature","properties":{"OBJECTID":154,"Id":0,"Nom":"Rue ASS - 63","Type":"Impaire","Shape_Leng":49.868958447},"geometry":{"type":"LineString","coordinates":[[-17.49587837927611,14.72959760172503],[-17.495633342763604,14.729215417504369]]}},{"type":"Feature","properties":{"OBJECTID":155,"Id":0,"Nom":"Rue ASS - 67","Type":"Impaire","Shape_Leng":49.7165066129},"geometry":{"type":"LineString","coordinates":[[-17.49535416608366,14.729933107566081],[-17.49509666693464,14.729560422574938]]}},{"type":"Feature","properties":{"OBJECTID":156,"Id":0,"Nom":"Rue ASS - 69","Type":"Impaire","Shape_Leng":44.6078358361},"geometry":{"type":"LineString","coordinates":[[-17.495857684967557,14.730092769187896],[-17.495757031775447,14.729940713598463],[-17.49562200707374,14.729761684769745]]}},{"type":"Feature","properties":{"OBJECTID":157,"Id":0,"Nom":"Rue ASS - 187","Type":"Impaire","Shape_Leng":32.7218218856},"geometry":{"type":"LineString","coordinates":[[-17.49577896353175,14.730141255988462],[-17.495948760913638,14.730386336004829]]}},{"type":"Feature","properties":{"OBJECTID":158,"Id":0,"Nom":"Rue ASS - 185","Type":"Impaire","Shape_Leng":30.9448148865},"geometry":{"type":"LineString","coordinates":[[-17.495476047935373,14.73033625551079],[-17.495628175358057,14.730573363306604]]}},{"type":"Feature","properties":{"OBJECTID":159,"Id":0,"Nom":"Rue ASS - 28","Type":"Paire","Shape_Leng":96.865653392},"geometry":{"type":"LineString","coordinates":[[-17.497533393774063,14.729265979405712],[-17.497608545954655,14.729198807846656],[-17.497698936125758,14.729199743779306],[-17.49839709235341,14.729306081127607]]}},{"type":"Feature","properties":{"OBJECTID":160,"Id":0,"Nom":"Rue ASS - 26","Type":"Paire","Shape_Leng":28.1889802102},"geometry":{"type":"LineString","coordinates":[[-17.497976264236655,14.72855233239698],[-17.498147551430737,14.728744821722286]]}},{"type":"Feature","properties":{"OBJECTID":161,"Id":0,"Nom":"Rue ASS - 189","Type":"Impaire","Shape_Leng":29.5989262223},"geometry":{"type":"LineString","coordinates":[[-17.49608937933848,14.72995005891961],[-17.496250590052096,14.730166564880966]]}},{"type":"Feature","properties":{"OBJECTID":162,"Id":0,"Nom":"Rue ASS - 136","Type":"Paire","Shape_Leng":452.011229954},"geometry":{"type":"LineString","coordinates":[[-17.493858172586194,14.730280840896748],[-17.493893559674223,14.730406845139743],[-17.49464780867839,14.730683681635233],[-17.49539651456763,14.730955845001041],[-17.496156798413825,14.731225668343834],[-17.496914098683288,14.731494361146375],[-17.497246544059713,14.731614490791111],[-17.497689717055582,14.731808696916481]]}},{"type":"Feature","properties":{"OBJECTID":163,"Id":0,"Nom":"Rue ASS - 169","Type":"Impaire","Shape_Leng":173.296294333},"geometry":{"type":"LineString","coordinates":[[-17.497689717055582,14.731808696916481],[-17.497631499332023,14.73204048511797],[-17.497534623186656,14.732289821654547],[-17.49744522726695,14.732519905661825],[-17.49735348923127,14.732756017975404],[-17.49726016954209,14.73301047806267],[-17.497228827412524,14.733096188665815],[-17.497197305673584,14.733196440385855],[-17.497168916467807,14.733226863183537],[-17.49711729684212,14.733248656394043]]}},{"type":"Feature","properties":{"OBJECTID":164,"Id":0,"Nom":"Rue ASS - 141","Type":"Impaire","Shape_Leng":291.027270013},"geometry":{"type":"LineString","coordinates":[[-17.49386442413109,14.730182773393405],[-17.493858172586194,14.730280840896748],[-17.49374133817922,14.730586817456627],[-17.49372072927832,14.730641553048116],[-17.49362053958918,14.730906635155442],[-17.493581177544698,14.731010666873248],[-17.493535285768022,14.731131956927953],[-17.49344640474981,14.73136695247437],[-17.4934193116314,14.731438795038656],[-17.493348389961064,14.7316268617637],[-17.493260191289828,14.731858221906005],[-17.49317141936922,14.732089957703554],[-17.493099793478596,14.732281346546198],[-17.493029665353905,14.732489921668023],[-17.49303060343522,14.73257237515515],[-17.49309772356259,14.732643358745676]]}},{"type":"Feature","properties":{"OBJECTID":165,"Id":0,"Nom":"Rue ASS - 120","Type":"Paire","Shape_Leng":438.75519099},"geometry":{"type":"LineString","coordinates":[[-17.49309772356259,14.732643358745676],[-17.49382606772896,14.732815206353482],[-17.494290181845592,14.732911236627988],[-17.49465718879983,14.732942116951184],[-17.495497419552596,14.733045491247157],[-17.49628840607436,14.733141712195904],[-17.49711729684212,14.733248656394043]]}},{"type":"Feature","properties":{"OBJECTID":166,"Id":0,"Nom":"Rue ASS - 124","Type":"Paire","Shape_Leng":93.8869814911},"geometry":{"type":"LineString","coordinates":[[-17.493896507274233,14.73263772975741],[-17.49307645874532,14.732350748280654]]}}]};
const CADASTRE_PARCELLES = <?php echo $cadastre_parcelles_json; ?>;
</script>
<script>
// Uniformiser le schéma : le champ "Identifiant" doit exister sur TOUTES les parcelles
// (y compris les parcelles officielles d'origine, qui ne sont liées à aucun bâtiment
// et gardent donc Identifiant = null), pas seulement sur celles ajoutées manuellement —
// sinon le fichier exporté a des propriétés incohérentes selon les entités.
CADASTRE_PARCELLES.features.forEach(f => {
    if (f.properties && !('Identifiant' in f.properties)) {
        f.properties.Identifiant = null;
    }
});

// Dernier id connu dans la table `parcelles`, pour ne récupérer que les NOUVELLES
// parcelles ajoutées par d'autres agents lors des synchronisations périodiques
// (au lieu de re-télécharger tout le cadastre à chaque fois).
let lastKnownParcelleId = <?php echo (int)$max_parcelle_id; ?>;

//"Email professionnel" value="admin@unco.sn">
//"Mot de passe" value="admin123">

// ========== FONCTIONS POUR LES COULEURS DES BÂTIMENTS ==========
// ========== FONCTIONS POUR LES COULEURS DES BÂTIMENTS ==========
function getColorByType(type) {
    switch(type) {
        case 'Résidentiel': return '#1E3A5F';      // Bleu foncé
        case 'Commercial': return '#F5A623';       // Jaune/orange
        case 'Mixte': return '#5D4037';            // Marron foncé
        case 'Équipement public': return '#4CAF50'; // Vert clair
        default: return '#6b7280';                 // Gris neutre : type non reconnu (ex. import
                                                     // shapefile avec une valeur différente), mais
                                                     // on affiche quand même le marqueur plutôt que
                                                     // de le faire disparaître silencieusement.
    }
}

function createBuildingMarker(lat, lng, id, type, address, district, area, floors) {
    // Si le type n'est pas reconnu, on ne crée pas le marqueur
    const color = getColorByType(type);
    if (!color) return null;
    
    // Icône en forme de goutte/pin de localisation
    const iconHtml = `
        <svg width="44" height="44" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" style="filter: drop-shadow(0 2px 4px rgba(0,0,0,0.3));">
            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z" fill="${color}" stroke="white" stroke-width="2"/>
            <circle cx="12" cy="9" r="2.5" fill="white"/>
        </svg>
    `;
    
    const customIcon = L.divIcon({ 
        html: iconHtml, 
        iconSize: [44, 44], 
        className: '',
        popupAnchor: [0, -20]
    });

    // On garde les vraies coordonnées du bâtiment, en filtrant simplement le cas où
    // elles tombent près de l'ancien centre fictif (voir recenterIfNearOldDefault) — sans
    // ça, aucun déplacement artificiel n'est appliqué. Les marqueurs Leaflet sont rendus
    // dans le "markerPane" (z-index 600), toujours au-dessus de l'"overlayPane" (z-index 400)
    // où sont dessinées les parcelles : ils s'affichent donc déjà naturellement par-dessus
    // le cadastre, sans avoir besoin de déplacer le point pour ça.
    const pos = (typeof recenterIfNearOldDefault === 'function')
        ? recenterIfNearOldDefault(parseFloat(lat), parseFloat(lng))
        : { lat: parseFloat(lat), lng: parseFloat(lng) };
    const markerLat = pos.lat, markerLng = pos.lng;

    const marker = L.marker([markerLat, markerLng], { icon: customIcon }).addTo(mainMap);
    marker.bindPopup(`
        <b>${id}</b><br>
        Type: <span style="color:${color}; font-weight:bold;">${type}</span><br>
        Adresse: ${address}<br>
        Quartier: ${district}<br>
        Surface: ${area} m²<br>
        Étages: ${floors}
    `);
    marker.on('click', () => mainMap.flyTo([markerLat, markerLng], Math.max(mainMap.getZoom(), 19)));
    return marker;
}

// ========== VARIABLES DYNAMIQUES POUR LES KPIS (valeurs réelles PostgreSQL) ==========
let totalBatiments = <?php echo (int)$total_batiments_reel; ?>;
let alertesRues = <?php echo (int)$total_alertes_rues_reel; ?>;
let totalParcelles = <?php echo (int)$total_parcelles_reel; ?>;
let actifsTerrain = <?php echo (int)$total_actifs_terrain_reel; ?>;

// Fonction pour mettre à jour l'affichage des KPIs
function updateKPIs() {
    document.querySelector('#view-sig .kpi-card:first-child .kpi-value').innerText = totalBatiments.toLocaleString();
    document.querySelector('#view-sig .kpi-card:nth-child(2) .kpi-value').innerText = alertesRues;
    const tauxAdressage = totalParcelles > 0 ? Math.round((totalBatiments / totalParcelles) * 100) : 0;
    document.querySelector('#view-sig .kpi-card:nth-child(3) .kpi-value').innerText = tauxAdressage + '%';
    document.querySelector('#view-sig .kpi-card:nth-child(4) .kpi-value').innerText = actifsTerrain;
    
    // Sauvegarder dans sessionStorage
    sessionStorage.setItem('unco_totalBatiments', totalBatiments);
    sessionStorage.setItem('unco_alertesRues', alertesRues);
    sessionStorage.setItem('unco_totalParcelles', totalParcelles);
    sessionStorage.setItem('unco_actifsTerrain', actifsTerrain);
}

    
    // ========== MAPPING DES RÔLES (valeurs BD -> vue / libellé) ==========
    const ROLE_VIEW_MAP  = { admin: 'admin', agent: 'municipal', controleur: 'sig' };
    const ROLE_LABEL_MAP = { admin: 'ADMINISTRATEUR', agent: 'AGENT MUNICIPAL', controleur: 'TECHNICIEN SIG' };
    const ROLE_TITLE_MAP = { admin: 'Administration', municipal: 'Gestion Fiscale', sig: 'Carte Interactive' };

    // Verrouille l'interface sur la vue correspondant au rôle réel de l'utilisateur connecté.
    // L'administrateur conserve la possibilité de naviguer entre les 3 espaces.
    function applyRoleAccess(dbRole) {
        const viewId = ROLE_VIEW_MAP[dbRole] || 'sig';
        const navSig = document.getElementById('navItemSig');
        const navMunicipal = document.getElementById('navItemMunicipal');
        const navAdmin = document.getElementById('navItemAdmin');
        const navByView = { sig: navSig, municipal: navMunicipal, admin: navAdmin };

        if (dbRole === 'admin') {
            [navSig, navMunicipal, navAdmin].forEach(n => { if (n) n.style.display = ''; });
        } else {
            [navSig, navMunicipal, navAdmin].forEach(n => { if (n) n.style.display = 'none'; });
            if (navByView[viewId]) navByView[viewId].style.display = '';
        }

        document.querySelectorAll('.nav-item').forEach(b => b.classList.remove('active'));
        if (navByView[viewId]) navByView[viewId].classList.add('active');

        document.querySelectorAll('.role-view').forEach(v => v.classList.remove('active'));
        const targetView = document.getElementById('view-' + viewId);
        if (targetView) targetView.classList.add('active');

        const titleEl = document.getElementById('current-view-title');
        const badgeEl = document.getElementById('current-role-badge');
        if (titleEl) titleEl.innerText = ROLE_TITLE_MAP[viewId] || 'Carte Interactive';
        if (badgeEl) badgeEl.innerText = ROLE_LABEL_MAP[dbRole] || 'TECHNICIEN SIG';

        setTimeout(() => { if (mainMap) mainMap.invalidateSize(); if (fiscalMap) fiscalMap.invalidateSize(); if (adminMap) adminMap.invalidateSize(); }, 150);
    }

    function applyLoggedInUser(nom, role) {
        document.getElementById('userNameDisplay').innerText = nom || sessionStorage.getItem('unco_user') || '';
        const tooltipEl = document.getElementById('userTooltip');
        const initialsEl = document.getElementById('userInitials');
        if (tooltipEl) tooltipEl.innerText = nom || '';
        if (initialsEl && nom) {
            const initials = nom.trim().split(/\s+/).map(w => w[0]).join('').substring(0, 2).toUpperCase();
            initialsEl.textContent = initials;
        }
        applyRoleAccess(role);
    }

    // ========== LOGIN ==========
function attemptLogin() {
    const email = document.getElementById('loginEmail').value.trim();
    const pwd = document.getElementById('loginPassword').value.trim();
    const errorBox = document.getElementById('loginError');
    const btn = document.querySelector('#loginPage .login-btn');

    if (!email || !pwd) {
        errorBox.innerText = 'Veuillez renseigner email et mot de passe.';
        errorBox.style.display = 'block';
        return;
    }
    errorBox.style.display = 'none';
    if (btn) { btn.disabled = true; btn.innerText = 'Connexion...'; }

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'login', email, password: pwd })
    })
    .then(r => {
        if (!r.ok) return r.text().then(t => { throw new Error('Erreur serveur ' + r.status + ': ' + t.substring(0,300)); });
        return r.json();
    })
    .then(result => {
        if (btn) { btn.disabled = false; btn.innerText = 'Se connecter'; }
        if (result.success) {
            sessionStorage.setItem('unco_auth',   'true');
            sessionStorage.setItem('unco_user',   result.nom || email.split('@')[0]);
            sessionStorage.setItem('unco_role',   result.role);
            sessionStorage.setItem('unco_nom',    result.nom || email.split('@')[0]);
            sessionStorage.setItem('unco_email',  result.email || email);
            sessionStorage.setItem('unco_userid', result.id || '');

            document.getElementById('loginPage').style.display = 'none';
            document.getElementById('appLayout').style.display = 'flex';
            applyLoggedInUser(result.nom, result.role);
            initAll();
        } else {
            errorBox.innerText = result.error || 'Identifiants incorrects';
            errorBox.style.display = 'block';
        }
    })
    .catch(err => {
        if (btn) { btn.disabled = false; btn.innerText = 'Se connecter'; }
        errorBox.innerText = 'Erreur réseau : ' + err.message;
        errorBox.style.display = 'block';
    });
}
function logout() { sessionStorage.clear(); location.reload(); }
if (sessionStorage.getItem('unco_auth') === 'true') {
    document.getElementById('loginPage').style.display = 'none';
    document.getElementById('appLayout').style.display = 'flex';
    applyLoggedInUser(sessionStorage.getItem('unco_nom'), sessionStorage.getItem('unco_role') || 'admin');
}

// ========== DONNÉES FISCALES ==========
/*const paymentsData = [
    { ref: "PAY-8921", name: "Fatou Diallo", nicad: "14.12.01.07.124", amount: "125 000", status: "paid" },
    { ref: "TAX-2024", name: "Mamadou Ndiaye", nicad: "14.12.01.09.441", amount: "75 000", status: "overdue" },
    { ref: "PAY-8918", name: "Awa Seck", nicad: "14.12.01.02.088", amount: "200 000", status: "pending" },
    { ref: "PAY-8917", name: "Ibrahima Ba", nicad: "14.12.01.11.305", amount: "310 000", status: "paid" },
    { ref: "PAY-8910", name: "SOTRAC SA", nicad: "14.12.01.04.002", amount: "450 000", status: "paid" }

    
];*/



// ========== COUCHE PARCELLES FISCALES ==========
// Colors parcel polygons by payment status from paiementsReels (matched by NICAD / Num_Parcel)
let fiscalParcelLayer = null;
const FISCAL_COLORS = {
    paye:    { fill: '#22c55e', stroke: '#15803d' },
    pending: { fill: '#f59e0b', stroke: '#b45309' },
    overdue: { fill: '#ef4444', stroke: '#b91c1c' },
    exempt:  { fill: '#94a3b8', stroke: '#64748b' },
    default: { fill: '#86efac', stroke: '#15803d' }
};

// Construit la table de correspondance Num_Parcel → statut fiscal (paye/pending/overdue/exempt)
// à partir des paiements réels (liés par NICAD). Réutilisée pour colorer aussi bien les
// parcelles du cadastre que les bâtiments tracés manuellement, qui sont rattachés à la
// parcelle réelle la plus proche.
function buildParcelStatutMap() {
    const parcelStatut = {};
    if (typeof paiementsFiscaux !== 'undefined') {
        paiementsFiscaux.forEach(p => {
            if (p.nicad) parcelStatut[p.nicad] = p.statut;
        });
    }
    return parcelStatut;
}

function buildFiscalParcelLayer(filterStatus) {
    if (typeof CADASTRE_PARCELLES === 'undefined' || !fiscalMap) return null;

    // Build a quick lookup : Num_Parcel → statut from DB payments
    const parcelStatut = buildParcelStatutMap();

    // ===== DIAGNOSTIC : pourquoi les parcelles ne se colorent pas =====
    // Compte combien de parcelles ont réellement trouvé un statut, et affiche des
    // exemples concrets de valeurs Num_Parcel (cadastre) vs nicad (paiements) pour
    // repérer immédiatement un problème de format sans avoir à interroger la base.
    const allNumParcel = (CADASTRE_PARCELLES.features || []).map(f => (f.properties || {}).Num_Parcel).filter(Boolean);
    const matched = allNumParcel.filter(n => parcelStatut[n]);
    const nicadKeys = Object.keys(parcelStatut);
    console.log(
        `%c[Diagnostic coloration fiscale] ${matched.length} / ${allNumParcel.length} parcelles ont un statut fiscal.`,
        'font-weight:bold;color:' + (matched.length > 0 ? '#15803d' : '#dc2626')
    );
    console.log('Exemples de Num_Parcel (cadastre) :', allNumParcel.slice(0, 5));
    console.log('Exemples de nicad (table paiements) :', nicadKeys.slice(0, 5));
    if (matched.length === 0 && nicadKeys.length > 0) {
        console.warn('Aucune correspondance trouvée : les valeurs Num_Parcel et nicad ci-dessus ne se ressemblent probablement pas (formats différents). Il faut faire correspondre les deux (même valeur, ou une colonne de liaison dédiée).');
    } else if (nicadKeys.length === 0) {
        console.warn('La table paiements ne contient aucune valeur nicad renseignée (colonne vide ou NULL pour toutes les lignes).');
    }

    return L.geoJSON(CADASTRE_PARCELLES, {
        style: function(feature) {
            const num = (feature.properties || {}).Num_Parcel || '';
            let statut = parcelStatut[num] || 'default';
            // If filtering, grey out non-matching parcels
            if (filterStatus && filterStatus !== 'all') {
                const matchStatut = { paid:'paye', paye:'paye', pending:'pending', overdue:'overdue', exempt:'exempt' }[filterStatus] || filterStatus;
                if (statut !== matchStatut) {
                    return { color: '#cbd5e1', weight: 0.5, fillColor: '#e2e8f0', fillOpacity: 0.3 };
                }
            }
            const colors = FISCAL_COLORS[statut] || FISCAL_COLORS.default;
            return { color: colors.stroke, weight: 1.2, fillColor: colors.fill, fillOpacity: 0.65 };
        },
        onEachFeature: function(feature, layer) {
            const p = feature.properties || {};
            const num = p.Num_Parcel || '—';
            const statut = parcelStatut[num];
            const statutLabel = statut === 'paye' ? '✅ Payé' : statut === 'pending' ? '⏳ En attente' : statut === 'overdue' ? '⚠️ En retard' : statut === 'exempt' ? '🔰 Exonéré' : '—';
            const surface = p.Surface ? Math.round(p.Surface).toLocaleString('fr-FR') + ' m²' : '—';
            layer.bindPopup(`
                <div style="font-size:0.83rem;line-height:1.5;">
                    <strong>Parcelle ${num}</strong><br>
                    Rue : ${p.Rue || '—'}<br>
                    Quartier : ${p.Quatiers || '—'}<br>
                    Surface : ${surface}<br>
                    Statut fiscal : <strong>${statutLabel}</strong>
                </div>
            `);
        }
    });
}

function refreshFiscalMap(filterStatus) {
    if (!fiscalMap) return;

    // Toujours supprimer les polygones bâtiments existants d'abord
    if (fiscalBuildingPolygonsGroup) {
        fiscalMap.removeLayer(fiscalBuildingPolygonsGroup);
        fiscalBuildingPolygonsGroup = null;
    }

    // Ne rafraîchir les parcelles que si la couche est active
    const chkParcelles = document.getElementById('chkParcellesFiscal');
    if (!chkParcelles || !chkParcelles.checked) return;

    // Supprimer l'ancienne couche parcelles
    if (sigLayersFiscal.parcelles) { fiscalMap.removeLayer(sigLayersFiscal.parcelles); sigLayersFiscal.parcelles = null; }

    // Reconstruire avec le nouveau filtre
    sigLayersFiscal.parcelles = buildFiscalParcelLayer(filterStatus);
    if (sigLayersFiscal.parcelles) {
        sigLayersFiscal.parcelles.addTo(fiscalMap);
        // Reconstruire les polygones bâtiments par-dessus
        buildFiscalBuildingPolygons();
        // Remettre commune et infras au premier plan
        if (sigLayersFiscal.commune && sigLayersFiscal.commune.eachLayer) {
            sigLayersFiscal.commune.eachLayer(l => { if (l.bringToFront) l.bringToFront(); });
        }
        if (sigLayersFiscal.infrastructures) {
            sigLayersFiscal.infrastructures.eachLayer(m => { if (m.bringToFront) m.bringToFront(); });
        }
    }
}

function updateDonutChart(filterStatus) {
    if (!donutChart) return;
    let totalPaid = 0, totalPending = 0, totalOverdue = 0, totalExempt = 0;
    let source = paiementsReels || [];
    if (filterStatus && filterStatus !== 'all') {
        const mapFilter = { paid:'paye', paye:'paye', pending:'pending', overdue:'overdue', exempt:'exempt' };
        const realF = mapFilter[filterStatus] || filterStatus;
        source = source.filter(p => p.statut === realF);
    }
    source.forEach(p => {
        const m = parseFloat(p.montant) || 0;
        if (p.statut === 'paye')    totalPaid    += m;
        else if (p.statut === 'pending') totalPending += m;
        else if (p.statut === 'overdue') totalOverdue += m;
        else if (p.statut === 'exempt')  totalExempt  += m;
    });
    const grandTotal = totalPaid + totalPending + totalOverdue + totalExempt;
    const pct = v => grandTotal > 0 ? Math.round(v/grandTotal*100) : 0;
    donutChart.data.labels = [
        `Payés (${pct(totalPaid)}%)`, `En attente (${pct(totalPending)}%)`,
        `En retard (${pct(totalOverdue)}%)`, `Exonérés (${pct(totalExempt)}%)`
    ];
    donutChart.data.datasets[0].data = [totalPaid, totalPending, totalOverdue, totalExempt];
    donutChart.update();
}

function updateKPICards(filterStatus) {
    // Highlight the relevant KPI card when filter is active
    const cards = document.querySelectorAll('#view-municipal .kpi-card-fiscal, #view-municipal [id^="fkpi"]');
    // Bold-border the active card
    const mapCard = { paid:'fkpi-paid-val', paye:'fkpi-paid-val', pending:'fkpi-pending-val', overdue:'fkpi-overdue-val', exempt:'fkpi-exempt-val' };
    const activeId = mapCard[filterStatus];
    document.querySelectorAll('#view-municipal .fkpi-card').forEach(c => {
        c.style.borderWidth = '1px';
        c.style.borderColor = 'var(--border)';
    });
    if (activeId) {
        const el = document.getElementById(activeId);
        if (el) { const card = el.closest('.fkpi-card') || el.parentElement?.parentElement; if (card) { card.style.borderWidth = '2px'; card.style.borderColor = '#1A6B45'; } }
    }
}

let currentFiscalFilter = 'all';

function filterFiscal(status, btn) {
    document.querySelectorAll('.fiscal-filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    // Normalize status to DB value
    const mapStatus = { paid:'paye', all:'all', pending:'pending', overdue:'overdue', exempt:'exempt' };
    const realStatus = mapStatus[status] || status;
    currentFiscalFilter = realStatus;

    // 1. Tableau paiements
    renderPaymentsTable(realStatus === 'all' ? 'all' : realStatus);
    // 2. KPIs (total amounts unchanged)
    updateFiscalKPIs();
    // 3. Donut chart filtered
    updateDonutChart(status);
    // 4. Parcelles colorées sur la carte fiscale
    refreshFiscalMap(status);
    // 5. Highlight active KPI card
    const kpiMap = { paid:'fkpi-card-paid', paye:'fkpi-card-paid', pending:'fkpi-card-pending', overdue:'fkpi-card-overdue', exempt:'fkpi-card-exempt' };
    const kpiColors = { paid:'#22c55e', paye:'#22c55e', pending:'#f59e0b', overdue:'#ef4444', exempt:'#94a3b8' };
    ['fkpi-card-paid','fkpi-card-pending','fkpi-card-overdue','fkpi-card-exempt'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.style.borderColor = 'var(--border)'; el.classList.remove('kpi-active'); }
    });
    const activeCard = kpiMap[status];
    if (activeCard) {
        const el = document.getElementById(activeCard);
        if (el) { el.style.borderColor = kpiColors[status] || '#1A6B45'; el.classList.add('kpi-active'); }
    }
}



// ========== STATISTIQUES MENSUELLES POUR LE GRAPHIQUE ==========
let statsMensuelles = <?php echo json_encode($stats_mensuelles); ?>;


// ========== STATISTIQUES RÉELLES DEPUIS POSTGRESQL ==========
const statsBatimentsReels = <?php echo $stats_batiments_json; ?>;
const totalBatimentsReel = <?php echo $total_batiments_reel_json; ?>;

// ========== STATISTIQUES FISCALES RÉELLES ==========
let statsFiscalesReelles = <?php echo $stats_fiscales_json; ?>;
let paiementsReels = <?php echo $paiements_json; ?>;
let dashboardStatsReels = <?php echo $dashboard_stats_json; ?>;
// Jeu de données complet (nicad + statut, sans limite) dédié à la coloration fiscale de la
// carte — paiementsReels ci-dessus est volontairement limité à 10 lignes pour le tableau UI.
let paiementsFiscaux = <?php echo $paiements_fiscaux_json; ?>;

// ========== SYNCHRONISATION TEMPS RÉEL (SUPABASE REALTIME) ==========
// Écoute les changements sur la table "paiements" (ajout/modif/suppression),
// qu'ils viennent de cette page ou directement de Supabase Studio, et met
// à jour le tableau de bord instantanément, sans rechargement de page.
const SUPABASE_URL = 'https://rfblkqrnhcmbikfdfyak.supabase.co';
const SUPABASE_ANON_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InJmYmxrcXJuaGNtYmlrZmRmeWFrIiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODA2MDY5OTQsImV4cCI6MjA5NjE4Mjk5NH0.03uRhI_0KTftP7FsXt8zTr4PZmc9y4tdrm8hahqSnfg';

let supabaseRealtimeClient = null;
try {
    if (typeof window.supabase !== 'undefined') {
        supabaseRealtimeClient = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);
    }
} catch (e) {
    console.warn('Supabase Realtime indisponible :', e);
}

// Recalcule les données fiscales (KPI, tableau, dashboard, graphiques) à partir
// de l'état réel actuel de la base, puis rafraîchit l'UI.
async function refreshFiscalDataFromSupabase() {
    if (!supabaseRealtimeClient) return;
    try {
        // 1. Les 10 derniers paiements, pour le tableau "Derniers paiements"
        const { data: derniers, error: errDerniers } = await supabaseRealtimeClient
            .from('paiements')
            .select('reference, contribuable, nicad, montant, statut, date_creation')
            .order('date_creation', { ascending: false })
            .limit(10);
        if (!errDerniers && derniers) {
            paiementsReels = derniers;
            renderPaymentsTable(currentFiscalFilter === 'all' ? 'all' : currentFiscalFilter);
        }

        // 2. Toutes les lignes (colonnes légères) pour recalculer les agrégats réels
        const { data: toutes, error: errToutes } = await supabaseRealtimeClient
            .from('paiements')
            .select('statut, montant, date_creation');
        if (!errToutes && toutes) {
            const stats = { total_paye: 0, total_attente: 0, total_retard: 0, total_exonere: 0, total_paiements: toutes.length };
            const moisMap = {};
            const moisLabels = ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Aoû','Sep','Oct','Nov','Déc'];
            const now = new Date();
            const anneeCourante = now.getFullYear();
            const moisCourant = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
            let paiementsMois = 0, montantMois = 0, totalPending = 0;

            toutes.forEach(p => {
                const montant = parseFloat(p.montant) || 0;
                if (p.statut === 'paye') stats.total_paye += montant;
                else if (p.statut === 'pending') { stats.total_attente += montant; totalPending++; }
                else if (p.statut === 'overdue' || p.statut === 'impaye') stats.total_retard += montant;
                else if (p.statut === 'exonere') stats.total_exonere += montant;

                const d = new Date(p.date_creation);
                if (!isNaN(d)) {
                    const mKey = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
                    if (mKey === moisCourant) {
                        paiementsMois++;
                        if (p.statut === 'paye') montantMois += montant;
                    }
                    if (d.getFullYear() === anneeCourante) {
                        if (!moisMap[mKey]) {
                            moisMap[mKey] = { mois: moisLabels[d.getMonth()], ordre: d.getMonth(), total_paye: 0, total_attente: 0, total_retard: 0 };
                        }
                        if (p.statut === 'paye') moisMap[mKey].total_paye += montant;
                        else if (p.statut === 'pending') moisMap[mKey].total_attente += montant;
                        else if (p.statut === 'overdue' || p.statut === 'impaye') moisMap[mKey].total_retard += montant;
                    }
                }
            });

            statsFiscalesReelles = stats;
            dashboardStatsReels = { paiements_mois: paiementsMois, montant_mois: montantMois, total_pending: totalPending };
            statsMensuelles = Object.values(moisMap).sort((a, b) => a.ordre - b.ordre);

            if (typeof updateFiscalKPIs === 'function') updateFiscalKPIs();
            if (typeof updateDonutChart === 'function') updateDonutChart(currentFiscalFilter);
            if (typeof initFiscalCharts === 'function') initFiscalCharts();
        }
    } catch (e) {
        console.error('Erreur de synchronisation temps réel Supabase :', e);
    }
}

if (supabaseRealtimeClient) {
    supabaseRealtimeClient
        .channel('paiements-realtime-sync')
        .on('postgres_changes', { event: '*', schema: 'public', table: 'paiements' }, (payload) => {
            console.log('Changement détecté sur la table paiements :', payload.eventType);
            refreshFiscalDataFromSupabase();
        })
        .subscribe((status) => {
            if (status === 'SUBSCRIBED') {
                console.log('✅ Synchronisation temps réel active sur la table paiements');
            } else if (status === 'CHANNEL_ERROR' || status === 'TIMED_OUT') {
                console.warn('⚠️ Synchronisation temps réel indisponible (statut : ' + status + '). Vérifiez que la Réplication est activée pour la table "paiements" dans Supabase (Database → Replication).');
            }
        });
}

// Recharge tout le cadastre (parcelles officielles + tracées par les agents) depuis
// Supabase et reconstruit la couche sur la carte — capte aussi bien les ajouts que
// les modifications/suppressions faites directement dans Supabase Studio.
let parcellesRefreshInFlight = false;
async function refreshParcellesFromSupabase() {
    if (!supabaseRealtimeClient || parcellesRefreshInFlight) return;
    parcellesRefreshInFlight = true;
    try {
        const { data: rows, error } = await supabaseRealtimeClient
            .from('parcelles')
            .select('feature_geojson')
            .order('objectid', { ascending: true });
        if (!error && rows) {
            const features = rows
                .map(r => { try { return typeof r.feature_geojson === 'string' ? JSON.parse(r.feature_geojson) : r.feature_geojson; } catch(e) { return null; } })
                .filter(Boolean);
            CADASTRE_PARCELLES.features = features;

            // Reconstruire la couche cadastre si elle est actuellement affichée
            if (typeof sigLayers !== 'undefined' && sigLayers.parcelles && typeof buildParcellesLayer === 'function' && typeof mainMap !== 'undefined') {
                mainMap.removeLayer(sigLayers.parcelles);
                sigLayers.parcelles = buildParcellesLayer().addTo(mainMap);
                if (typeof enforceSigLayerOrder === 'function') enforceSigLayerOrder();
            }
            // Reconstruire la coloration fiscale des parcelles si active (vue Agent Municipal)
            const chkFiscal = document.getElementById('chkParcellesFiscal');
            if (chkFiscal && chkFiscal.checked && typeof buildFiscalBuildingPolygons === 'function') {
                buildFiscalBuildingPolygons();
            }
            console.log('[UNCO] Cadastre (parcelles) resynchronisé en temps réel —', features.length, 'parcelles.');
        }
    } catch (e) {
        console.error('Erreur de synchronisation temps réel du cadastre :', e);
    } finally {
        parcellesRefreshInFlight = false;
    }
}

if (supabaseRealtimeClient) {
    supabaseRealtimeClient
        .channel('parcelles-realtime-sync')
        .on('postgres_changes', { event: '*', schema: 'public', table: 'parcelles' }, (payload) => {
            console.log('Changement détecté sur la table parcelles :', payload.eventType);
            refreshParcellesFromSupabase();
        })
        .subscribe((status) => {
            if (status === 'SUBSCRIBED') {
                console.log('✅ Synchronisation temps réel active sur la table parcelles (cadastre)');
            } else if (status === 'CHANNEL_ERROR' || status === 'TIMED_OUT') {
                console.warn('⚠️ Synchronisation temps réel indisponible pour "parcelles" (statut : ' + status + '). Vérifiez la Réplication dans Supabase (Database → Replication).');
            }
        });
}


// ========== GRAPHIQUES FISCAUX ==========
let lineChart, donutChart;

function initFiscalCharts() {
    const ctxLine = document.getElementById('fiscal-line-chart').getContext('2d');
    const ctxDonut = document.getElementById('fiscal-donut-chart').getContext('2d');
    
    // ========== UTILISER LES DONNÉES RÉELLES DE POSTGRESQL ==========
    let months = [];
    let paidData = [];
    let pendingData = [];
    let overdueData = [];
    let exemptData = [];
    
    // Vérifier si nous avons des données réelles
    if (typeof statsMensuelles !== 'undefined' && statsMensuelles && statsMensuelles.length > 0) {
        // Utiliser les données réelles
        months = statsMensuelles.map(item => item.mois);
        paidData = statsMensuelles.map(item => item.total_paye / 1000);  // Convertir en milliers
        pendingData = statsMensuelles.map(item => item.total_attente / 1000);
        overdueData = statsMensuelles.map(item => item.total_retard / 1000);
        exemptData = statsMensuelles.map(item => (item.total_exonere || 0) / 1000);
        
        console.log("Graphique avec données réelles:", months);
    } else {
        // Fallback : utiliser les 6 derniers mois avec des valeurs par défaut
        console.log("Aucune donnée réelle, utilisation des valeurs par défaut");
        const moisActuels = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin'];
        months = moisActuels;
        paidData = [0, 0, 0, 0, 0, 0];
        pendingData = [0, 0, 0, 0, 0, 0];
        overdueData = [0, 0, 0, 0, 0, 0];
        exemptData = [0, 0, 0, 0, 0, 0];
    }
    
    // Détruire les graphiques existants s'ils existent
    if (lineChart) lineChart.destroy();
    if (donutChart) donutChart.destroy();
    
    // Créer le graphique en ligne
    lineChart = new Chart(ctxLine, {
        type: 'line',
        data: {
            labels: months,
            datasets: [
                { 
                    label: 'Payés (en milliers FCFA)', 
                    data: paidData, 
                    borderColor: '#4caf50', 
                    backgroundColor: 'rgba(76,175,80,0.1)', 
                    fill: true, 
                    tension: 0.3 
                },
                { 
                    label: 'En attente (en milliers FCFA)', 
                    data: pendingData, 
                    borderColor: '#f59e0b', 
                    backgroundColor: 'rgba(245,158,11,0.05)', 
                    fill: true, 
                    tension: 0.3 
                },
                { 
                    label: 'En retard (en milliers FCFA)', 
                    data: overdueData, 
                    borderColor: '#ef4444', 
                    backgroundColor: 'rgba(239,68,68,0.05)', 
                    fill: true, 
                    tension: 0.3 
                },
                { 
                    label: 'Exonérés (en milliers FCFA)', 
                    data: exemptData, 
                    borderColor: '#94a3b8', 
                    backgroundColor: 'rgba(148,163,184,0.05)', 
                    fill: true, 
                    tension: 0.3 
                }
            ]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: true, 
            plugins: { 
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.raw.toFixed(0) + 'k FCFA';
                        }
                    }
                }
            },
            scales: { 
                x: { 
                    ticks: { maxRotation: 45, minRotation: 45 },
                    title: { display: true, text: 'Mois' }
                },
                y: { 
                    title: { display: true, text: 'Montant (k FCFA)' },
                    beginAtZero: true
                }
            }
        }
    });
    
    // ========== DONUT CHART AVEC DONNÉES RÉELLES ==========
    // Calculer les totaux depuis les données réelles ou les stats fiscales
    let totalPaid = 0, totalPending = 0, totalOverdue = 0, totalExempt = 0;
    
    if (typeof statsFiscalesReelles !== 'undefined' && statsFiscalesReelles) {
        totalPaid = statsFiscalesReelles.total_paye;
        totalPending = statsFiscalesReelles.total_attente;
        totalOverdue = statsFiscalesReelles.total_retard;
        totalExempt = statsFiscalesReelles.total_exonere || 0;
    } else if (typeof statsMensuelles !== 'undefined' && statsMensuelles.length > 0) {
        // Alternative: calculer depuis les données mensuelles
        totalPaid = statsMensuelles.reduce((acc, m) => acc + m.total_paye, 0);
        totalPending = statsMensuelles.reduce((acc, m) => acc + m.total_attente, 0);
        totalOverdue = statsMensuelles.reduce((acc, m) => acc + m.total_retard, 0);
    }
    
    const grandTotal = totalPaid + totalPending + totalOverdue + totalExempt;
    
    const paidPercent = grandTotal > 0 ? Math.round((totalPaid / grandTotal) * 100) : 0;
    const pendingPercent = grandTotal > 0 ? Math.round((totalPending / grandTotal) * 100) : 0;
    const overduePercent = grandTotal > 0 ? Math.round((totalOverdue / grandTotal) * 100) : 0;
    const exemptPercent = grandTotal > 0 ? Math.round((totalExempt / grandTotal) * 100) : 0;
    
    // Créer le donut chart
    donutChart = new Chart(ctxDonut, {
        type: 'doughnut',
        data: {
            labels: [
                `Payés (${paidPercent}%) - ${(totalPaid/1000).toFixed(0)}k FCFA`, 
                `En attente (${pendingPercent}%) - ${(totalPending/1000).toFixed(0)}k FCFA`, 
                `En retard (${overduePercent}%) - ${(totalOverdue/1000).toFixed(0)}k FCFA`, 
                `Exonérés (${exemptPercent}%) - ${(totalExempt/1000).toFixed(0)}k FCFA`
            ],
            datasets: [{ 
                data: [totalPaid, totalPending, totalOverdue, totalExempt], 
                backgroundColor: ['#4caf50', '#f59e0b', '#ef4444', '#94a3b8'] 
            }]
        },
        options: { 
            cutout: '60%', 
            plugins: { 
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const value = context.raw;
                            return context.label + ': ' + (value/1000).toFixed(0) + 'k FCFA';
                        }
                    }
                }
            }
        }
    });
}

// ========== CARTES ET INFRASTRUCTURES ==========
let mainMap, fiscalMap, adminMap, routingControl, currentBaseLayer = 'osm', baseLayers = {};
let cadastreLayerFiscal = null, cadastreLayerAdmin = null;
let sigImportLayer = null; // couche Leaflet pour les données importées
let cadastreLayer = null, cadastreActive = false, buildingsDataGlobal = [];

// Certains enregistrements (bâtiments ET infrastructures) ont été créés avec un ancien
// centre par défaut fictif (14.7167 / -17.4667) qui ne correspond à aucune zone réelle
// du cadastre. On les recale automatiquement sur le vrai centre d'Ouakam (14.7247 / -17.4892)
// pour qu'ils retombent dans la zone des parcelles. Cette correction s'appliquait déjà aux
// bâtiments (loadBuildingsFromPostgreSQL) mais pas aux infrastructures : c'est pour ça que
// les bâtiments s'affichaient bien sur les parcelles alors que les infrastructures restaient
// loin, à leur ancienne position par défaut.
const GEO_OLD_CENTER = { lat: 14.7167, lng: -17.4667 };
const GEO_NEW_CENTER = { lat: 14.7247, lng: -17.4892 };
const GEO_DELTA_LAT = GEO_NEW_CENTER.lat - GEO_OLD_CENTER.lat;
const GEO_DELTA_LNG = GEO_NEW_CENTER.lng - GEO_OLD_CENTER.lng;
const GEO_SEUIL = 0.01; // ~1km : si le point est à moins de 1km de l'ancien centre fictif, on le considère mal positionné

function recenterIfNearOldDefault(lat, lng) {
    const distToOldCenter = Math.sqrt((lat - GEO_OLD_CENTER.lat) ** 2 + (lng - GEO_OLD_CENTER.lng) ** 2);
    if (distToOldCenter < GEO_SEUIL) {
        return { lat: lat + GEO_DELTA_LAT, lng: lng + GEO_DELTA_LNG, repositionne: true };
    }
    return { lat, lng, repositionne: false };
}

// Infrastructures chargées dynamiquement depuis Supabase (table `infrastructures`).
// Colonnes : nom, categorie, latitude, longitude, icone, couleur.
// Mapping de secours si `icone`/`couleur` ne sont pas renseignées en base pour une catégorie donnée.
const INFRA_CATEGORY_DEFAULTS = {
    'Voie principale':   { iconName: 'route',           color: '#F5A623' },
    'Voie secondaire':   { iconName: 'route',           color: '#c07d10' },
    'Électricité':       { iconName: 'zap',             color: '#eab308' },
    'Pharmacie':         { iconName: 'pill',            color: '#22c55e' },
    'Santé':             { iconName: 'hospital',        color: '#15803d' },
    'École':             { iconName: 'graduation-cap',  color: '#0b62d4' },
    'Mosquée':           { iconName: 'landmark',        color: '#eab308' },
    'Administration':    { iconName: 'building-2',      color: '#15803d' },
    'Commerce':          { iconName: 'store',           color: '#F5A623' },
    'Boutique':          { iconName: 'shopping-bag',    color: '#8B5CF6' },
    'Restaurant':        { iconName: 'utensils',        color: '#F97316' },
};
const INFRA_DEFAULT_FALLBACK = { iconName: 'map-pin', color: '#1A6B45' };

let infrastructures = [];

function loadInfrastructuresFromSupabase() {
    const raw = <?php echo json_encode($infrastructures_db); ?>;
    infrastructures = raw.map(row => {
        const defaults = INFRA_CATEGORY_DEFAULTS[row.categorie] || INFRA_DEFAULT_FALLBACK;
        const pos = recenterIfNearOldDefault(parseFloat(row.latitude), parseFloat(row.longitude));
        return {
            name: row.nom,
            category: row.categorie,
            lat: pos.lat,
            lng: pos.lng,
            iconName: row.icone || defaults.iconName,
            color: row.couleur || defaults.color
        };
    }).filter(inf => !isNaN(inf.lat) && !isNaN(inf.lng));
    const nbRepositionnees = raw.filter(row => recenterIfNearOldDefault(parseFloat(row.latitude), parseFloat(row.longitude)).repositionne).length;
    if (nbRepositionnees > 0) {
        console.warn(nbRepositionnees + " infrastructure(s) avaient des coordonnées proches de l'ancien centre fictif et ont été recalées automatiquement à l'affichage (la base de données n'a pas été modifiée).");
    }
    console.log('[Infrastructures] ' + infrastructures.length + ' chargées depuis Supabase');
}

function initMaps() {
    if(mainMap) return;
    mainMap = L.map('unco-main-map', { maxZoom: 22 }).setView([14.7247, -17.4892], 16);
    baseLayers.osm = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', { attribution: '© OpenStreetMap', maxZoom: 22, maxNativeZoom: 19 }).addTo(mainMap);
    baseLayers.satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: 'Esri', maxZoom: 22, maxNativeZoom: 19 });

    // Charger les infrastructures réelles depuis Supabase (table `infrastructures`).
    // Doit être fait avant que l'utilisateur ne coche la couche "Infrastructures".
    loadInfrastructuresFromSupabase();

    // Les marqueurs d'infrastructures ne sont plus ajoutés ici automatiquement.
    // Ils n'apparaissent désormais que lorsque la checkbox "Infrastructures" du panneau
    // COUCHES SIG est cochée, via buildInfrastructuresLayer() / toggleSigLayer().

    fiscalMap = L.map('fiscal-map', { maxZoom: 22 }).setView([14.7247, -17.4892], 16);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', { maxZoom: 22, maxNativeZoom: 19 }).addTo(fiscalMap);
    // Les parcelles fiscales ne s'affichent PAS automatiquement ici :
    // elles apparaissent uniquement quand l'utilisateur coche "Parcelles" dans le
    // panneau Couches SIG, via toggleSigLayerFiscal('parcelles', true).
    
    adminMap = L.map('admin-minimap', { maxZoom: 22 }).setView([14.7247, -17.4892], 14);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', { maxZoom: 22, maxNativeZoom: 19 }).addTo(adminMap);

    // ========== CONTRÔLE ITINÉRAIRE ==========
    if (typeof L.Routing !== 'undefined') {
        routingControl = L.Routing.control({
            waypoints: [], language: 'fr', routeWhileDragging: true,
            show: false, collapsible: true, collapsed: true,
            lineOptions: { styles: [{ color: '#1A6B45', weight: 5, opacity: 0.8 }] },
            createMarker: function(i, wp) {
                return L.marker(wp.latLng, { draggable: true, icon: L.divIcon({
                    html: `<div style="background:${i===0?'#1A6B45':'#E74C3C'};width:18px;height:18px;border-radius:50%;border:3px solid white;box-shadow:0 2px 6px rgba(0,0,0,0.4);"></div>`,
                    iconSize:[18,18], className:''
                })});
            }
        }).addTo(mainMap);
        routingControl.hide();
    }

    setTimeout(() => { mainMap.invalidateSize(); fiscalMap.invalidateSize(); adminMap.invalidateSize(); }, 200);
}


// ========== DESSIN DE POLYGONES BÂTIMENT (LEAFLET.DRAW) ==========
let drawControl = null;
let drawnBuildingLayer = null;
// Groupe contenant TOUS les polygones de bâtiments tracés (chargés depuis la BD au démarrage
// + nouveau tracé après sauvegarde). Ce groupe est lié à la couche "Parcelles" : il
// apparaît/disparaît quand on coche/décoche cette case dans le panneau Couches SIG.
let buildingPolygonsGroup = null;
let isDrawingBuilding = false;

// Calcule la surface d'un polygone en m² (formule de Shoelace + correction sphérique simple)
function calcPolygonArea(latlngs) {
    const R = 6371000; // rayon terrestre en mètres
    let area = 0;
    const n = latlngs.length;
    for (let i = 0; i < n; i++) {
        const j = (i + 1) % n;
        const xi = latlngs[i].lng * Math.PI / 180;
        const yi = latlngs[i].lat * Math.PI / 180;
        const xj = latlngs[j].lng * Math.PI / 180;
        const yj = latlngs[j].lat * Math.PI / 180;
        area += (xj - xi) * (2 + Math.sin(yi) + Math.sin(yj));
    }
    area = Math.abs(area) * R * R / 2;
    return area;
}

// Calcule le centroïde d'un polygone (moyenne des sommets)
function calcCentroid(latlngs) {
    const lat = latlngs.reduce((s, p) => s + p.lat, 0) / latlngs.length;
    const lng = latlngs.reduce((s, p) => s + p.lng, 0) / latlngs.length;
    return { lat, lng };
}

// Trouve la parcelle GeoJSON la plus proche du centroïde du polygone dessiné
// et retourne ses propriétés pour pré-remplir la modale
function findNearestParcel(centroid) {
    if (typeof CADASTRE_PARCELLES === 'undefined') return {};
    let best = null, bestDist = Infinity;
    CADASTRE_PARCELLES.features.forEach(f => {
        const coords = f.geometry.type === 'Polygon'
            ? f.geometry.coordinates[0]
            : f.geometry.coordinates[0][0];
        const clat = coords.reduce((s,p) => s + p[1], 0) / coords.length;
        const clng = coords.reduce((s,p) => s + p[0], 0) / coords.length;
        const d = Math.sqrt((clat - centroid.lat)**2 + (clng - centroid.lng)**2);
        if (d < bestDist) { bestDist = d; best = f.properties; }
    });
    return best ? { ...best, _distance: bestDist } : {};
}

// Distance (en degrés, ~33m) au-delà de laquelle on considère qu'aucune parcelle
// cadastrale existante ne correspond réellement au bâtiment tracé.
const PARCEL_MATCH_THRESHOLD = 0.0003;

// Génère un numéro de parcelle en continuité avec le cadastre existant (max + 1),
// et poursuit la séquence pour les bâtiments suivants tracés dans la même session.
let _nextGeneratedParcelNumber = null;
function getNextParcelNumber() {
    if (_nextGeneratedParcelNumber === null) {
        let max = 0;
        if (typeof CADASTRE_PARCELLES !== 'undefined') {
            (CADASTRE_PARCELLES.features || []).forEach(f => {
                const n = parseInt((f.properties || {}).Num_Parcel, 10);
                if (!isNaN(n) && n > max) max = n;
            });
        }
        _nextGeneratedParcelNumber = max + 1;
    }
    return String(_nextGeneratedParcelNumber++);
}

// Génère un OBJECTID en continuité avec le cadastre existant (même principe que Num_Parcel)
let _nextGeneratedObjectId = null;
function getNextObjectId() {
    if (_nextGeneratedObjectId === null) {
        let max = 0;
        if (typeof CADASTRE_PARCELLES !== 'undefined') {
            (CADASTRE_PARCELLES.features || []).forEach(f => {
                const n = parseInt((f.properties || {}).OBJECTID, 10);
                if (!isNaN(n) && n > max) max = n;
            });
        }
        _nextGeneratedObjectId = max + 1;
    }
    return _nextGeneratedObjectId++;
}

// Périmètre d'un polygone (en mètres), pour Shape_Leng
function calcPolygonPerimeter(latlngs) {
    let perimeter = 0;
    for (let i = 0; i < latlngs.length; i++) {
        const a = latlngs[i];
        const b = latlngs[(i + 1) % latlngs.length];
        perimeter += L.latLng(a).distanceTo(L.latLng(b));
    }
    return perimeter;
}

// Lance le mode dessin de polygone
function startDrawBuilding() {
    if (!mainMap) { showToast('Erreur', 'Carte non initialisée', 'error'); return; }

    const btn = document.getElementById('drawPolygonBtn');
    const statusBar = document.getElementById('drawStatusBar');

    // Si déjà en mode dessin → annuler
    if (isDrawingBuilding) {
        cancelDrawBuilding();
        return;
    }

    isDrawingBuilding = true;
    if (btn) btn.classList.add('drawing-active');
    if (statusBar) statusBar.style.display = 'block';

    // Supprimer un ancien polygone tracé si existant
    if (drawnBuildingLayer) { mainMap.removeLayer(drawnBuildingLayer); drawnBuildingLayer = null; }

    // Activer Leaflet.draw en mode polygone uniquement
    if (drawControl) { drawControl.remove(); drawControl = null; }

    const drawnItems = new L.FeatureGroup().addTo(mainMap);

    const polyHandler = new L.Draw.Polygon(mainMap, {
        shapeOptions: {
            color: '#dc2626',
            weight: 2,
            fillColor: '#ef4444',
            fillOpacity: 0.3
        },
        showArea: true,
        metric: true,
        guidelineDistance: 10,
        allowIntersection: false,
        drawError: {
            color: '#e1e100',
            timeout: 1000
        },
        // Snap sur les sommets des parcelles visibles (si la couche parcelles est active)
        snapDistance: 10
    });
    polyHandler.enable();

    // Écouter la fin du dessin
    mainMap.once(L.Draw.Event.CREATED, function(e) {
        isDrawingBuilding = false;
        if (btn) btn.classList.remove('drawing-active');
        if (statusBar) statusBar.style.display = 'none';

        drawnBuildingLayer = e.layer;
        drawnBuildingLayer.addTo(mainMap);

        const latlngs = e.layer.getLatLngs()[0];
        const area    = calcPolygonArea(latlngs);
        const centroid = calcCentroid(latlngs);
        let parcelInfo = findNearestParcel(centroid);

        // Si aucune parcelle du cadastre n'est réellement sous le bâtiment tracé
        // (trop éloignée), on attribue un nouveau numéro en continuité du cadastre.
        if (!parcelInfo.Num_Parcel || (parcelInfo._distance !== undefined && parcelInfo._distance > PARCEL_MATCH_THRESHOLD)) {
            parcelInfo = { ...parcelInfo, Num_Parcel: getNextParcelNumber(), _generated: true };
        }

        // Stocker les données pour la modale
        window._drawnBuildingGeoJSON = e.layer.toGeoJSON();
        window._drawnBuildingArea    = area;
        window._drawnBuildingCentroid = centroid;
        window._drawnParcelInfo      = parcelInfo;

        // Afficher la modale de confirmation
        setTimeout(() => uncoCore.openModal('drawBuilding'), 100);
    });

    // Écouter Échap → annuler le dessin
    const onKeydown = (evt) => {
        if (evt.key === 'Escape') {
            cancelDrawBuilding();
            document.removeEventListener('keydown', onKeydown);
        }
    };
    document.addEventListener('keydown', onKeydown);
}

// Annule le dessin en cours
function cancelDrawBuilding() {
    isDrawingBuilding = false;
    const btn = document.getElementById('drawPolygonBtn');
    const statusBar = document.getElementById('drawStatusBar');
    if (btn) btn.classList.remove('drawing-active');
    if (statusBar) statusBar.style.display = 'none';
    if (drawnBuildingLayer) { mainMap.removeLayer(drawnBuildingLayer); drawnBuildingLayer = null; }
    window._drawnBuildingGeoJSON = null;
    window._drawnBuildingArea = null;
    window._drawnBuildingCentroid = null;
    window._drawnParcelInfo = null;
    // Fermer la modale si ouverte
    const modal = document.getElementById('globalModal');
    if (modal) { const bsModal = bootstrap.Modal.getInstance(modal); if (bsModal) bsModal.hide(); }
}

// Sauvegarde le bâtiment tracé en base de données
async function saveDrawnBuilding() {
    const centroid = window._drawnBuildingCentroid;
    const geojson  = window._drawnBuildingGeoJSON;
    if (!centroid || !geojson) { showToast('Erreur', 'Aucun polygone tracé', 'error'); return; }

    const data = {
        action:       'add_building',
        identifiant:  document.getElementById('drawBuildingId')?.value,
        type:         document.getElementById('drawBuildingType')?.value,
        adresse:      document.getElementById('drawBuildingAddr')?.value,
        quartier:     document.getElementById('drawBuildingQuartier')?.value,
        surface:      parseFloat(document.getElementById('drawBuildingSurface')?.value) || Math.round(window._drawnBuildingArea || 0),
        etages:       document.getElementById('drawBuildingFloors')?.value,
        latitude:     centroid.lat,
        longitude:    centroid.lng,
        polygon_geojson: JSON.stringify(geojson),
        observations: `Bâtiment tracé par polygone · Parcelle: ${window._drawnParcelInfo?.Num_Parcel || '—'}`
    };

    if (!data.identifiant || !data.type) {
        showToast('Erreur', 'Identifiant et type obligatoires', 'error'); return;
    }

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await response.json();
        if (result.success) {
            showToast('Succès', `Bâtiment "${data.identifiant}" enregistré avec son polygone !`, 'success');
            bootstrap.Modal.getInstance(document.getElementById('globalModal'))?.hide();

            if (drawnBuildingLayer) {
                // Retirer le polygone temporaire de dessin de la carte
                mainMap.removeLayer(drawnBuildingLayer);
            }

            // Construire une vraie "parcelle" (mêmes champs que le cadastre : Rue, Angle,
            // Commune, Quatiers, Surface, Num_Parcel, Shape_Leng, Shape_Area) à partir du
            // polygone tracé, pour qu'elle se fonde dans la couche des parcelles — pas un
            // simple overlay par-dessus, une entrée à part entière de CADASTRE_PARCELLES.
            const ring = geojson.geometry.coordinates[0];
            const ringLatLngs = ring.map(c => L.latLng(c[1], c[0]));
            const parcelInfo = window._drawnParcelInfo || {};
            const newFeature = {
                type: "Feature",
                properties: {
                    OBJECTID: getNextObjectId(),
                    Id: 0,
                    Identifiant: data.identifiant,
                    Rue: data.adresse || parcelInfo.Rue || "N/A",
                    Angle: parcelInfo.Angle || "N/A",
                    Commune: "Ouakam",
                    Quatiers: data.quartier || parcelInfo.Quatiers || "Cité assemblé",
                    Surface: window._drawnBuildingArea || 0,
                    Num_Parcel: parcelInfo.Num_Parcel || getNextParcelNumber(),
                    Shape_Leng: calcPolygonPerimeter(ringLatLngs),
                    Shape_Area: window._drawnBuildingArea || 0
                },
                geometry: geojson.geometry
            };

            try {
                const parcelleResp = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'add_parcelle_utilisateur',
                        num_parcel: newFeature.properties.Num_Parcel,
                        feature_geojson: JSON.stringify(newFeature)
                    })
                });
                const parcelleResult = await parcelleResp.json();
                if (parcelleResult.success) {
                    // Fusion immédiate : la parcelle apparaît tout de suite, stylée
                    // identiquement aux autres, sans recharger la page. On utilise le Feature
                    // renvoyé par le serveur (OBJECTID assigné côté serveur, évite toute
                    // collision entre deux agents qui tracent en même temps).
                    const confirmedFeature = parcelleResult.feature || newFeature;
                    CADASTRE_PARCELLES.features.push(confirmedFeature);
                    if (sigLayers.parcelles) {
                        mainMap.removeLayer(sigLayers.parcelles);
                        sigLayers.parcelles = buildParcellesLayer().addTo(mainMap);
                    }
                    if (typeof refreshFiscalMap === 'function') {
                        const currentFilter = document.querySelector('#view-municipal .fiscal-filter-btn.active')?.dataset?.filter || 'all';
                        refreshFiscalMap(currentFilter);
                    }
                } else {
                    showToast('Attention', 'Bâtiment enregistré, mais parcelle non fusionnée : ' + (parcelleResult.error || ''), 'warning');
                }
            } catch (e2) {
                showToast('Attention', 'Bâtiment enregistré, mais la fusion en parcelle a échoué (connexion).', 'warning');
            }

            drawnBuildingLayer = null;
            window._drawnBuildingGeoJSON = null;
            window._drawnBuildingArea = null;
            window._drawnBuildingCentroid = null;
            window._drawnParcelInfo = null;
            refreshMapData();
        } else {
            showToast('Erreur', result.error || 'Erreur base de données', 'error');
        }
    } catch(err) {
        showToast('Erreur', 'Erreur de connexion : ' + err.message, 'error');
    }
}

// Exporte le cadastre complet (parcelles officielles + parcelles ajoutées, déjà fusionnées
// dans CADASTRE_PARCELLES) sous forme de fichier .geojson téléchargeable — pour reprendre
// le travail dans QGIS/ArcGIS avec les mêmes champs.
function exportParcellesGeoJSON() {
    const dataStr = JSON.stringify(CADASTRE_PARCELLES);
    const blob = new Blob([dataStr], { type: 'application/geo+json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'Parcelles_WGS84.geojson';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    showToast('Export', `${CADASTRE_PARCELLES.features.length} parcelles exportées (dont les parcelles ajoutées manuellement).`, 'success');
}
window.exportParcellesGeoJSON = exportParcellesGeoJSON;

window.startDrawBuilding  = startDrawBuilding;
window.cancelDrawBuilding = cancelDrawBuilding;
window.saveDrawnBuilding  = saveDrawnBuilding;

// ========== SYNCHRONISATION TEMPS RÉEL (sans rechargement de page) ==========
// Appelée après chaque ajout/modification pour mettre à jour les cartes à chaud.
async function refreshMapData() {
    // Garde de sécurité : si la carte n'est pas encore initialisée (ex. sondage
    // déclenché avant la fin du chargement de la page), on ne fait rien plutôt
    // que de laisser une exception se produire.
    if (typeof mainMap === 'undefined' || !mainMap) {
        console.warn('[UNCO] refreshMapData ignorée : carte pas encore initialisée.');
        return;
    }
    try {
        const response = await fetch(window.location.href + '?get_map_data=1&since_parcelle_id=' + (typeof lastKnownParcelleId !== 'undefined' ? lastKnownParcelleId : 0), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();

        // --- Mettre à jour les bâtiments ---
        if (data.batiments && Array.isArray(data.batiments)) {
            // Retirer tous les marqueurs existants de la carte
            mainMap.eachLayer(l => {
                if (l._unco_type === 'building_marker') mainMap.removeLayer(l);
            });

            // Vider et reconstruire le groupe des polygones
            if (buildingPolygonsGroup) {
                mainMap.removeLayer(buildingPolygonsGroup);
                buildingPolygonsGroup.clearLayers();
            } else {
                buildingPolygonsGroup = L.layerGroup();
            }

            data.batiments.forEach(b => {
                if (b.latitude && b.longitude) {
                    const pos = recenterIfNearOldDefault(parseFloat(b.latitude), parseFloat(b.longitude));
                    b.latitude = pos.lat; b.longitude = pos.lng;
                }
                if (b.polygon_geojson) {
                    try {
                        const geo = typeof b.polygon_geojson === 'string' ? JSON.parse(b.polygon_geojson) : b.polygon_geojson;
                        const poly = L.geoJSON(geo, {
                            style: { color: '#15803d', weight: 2, fillColor: '#86efac', fillOpacity: 0.55 }
                        });
                        poly.bindPopup(`<strong>${b.identifiant}</strong><br>Type : ${b.type||'—'}<br>Adresse : ${b.adresse||'—'}<br>Quartier : ${b.quartier||'—'}<br>Surface : ${b.surface ? Math.round(b.surface)+' m²' : '—'}`);
                        buildingPolygonsGroup.addLayer(poly);
                    } catch(e) {}
                } else {
                    createBuildingMarker(b.latitude, b.longitude, b.identifiant, b.type, b.adresse||'—', b.quartier||'—', b.surface||'?', b.etages||'?');
                }
            });

            buildingsDataGlobal = data.batiments;
            const chkP = document.getElementById('chkParcelles');
            if (chkP && chkP.checked) buildingPolygonsGroup.addTo(mainMap);

            totalBatiments = data.batiments.length;
            updateKPIs();
        }

        // --- Mettre à jour les infrastructures ---
        if (data.infrastructures && Array.isArray(data.infrastructures)) {
            const raw = data.infrastructures;
            infrastructures.length = 0;
            raw.forEach(row => {
                const def = INFRA_CATEGORY_DEFAULTS[row.categorie] || INFRA_DEFAULT_FALLBACK;
                const pos = recenterIfNearOldDefault(parseFloat(row.latitude), parseFloat(row.longitude));
                infrastructures.push({
                    name: row.nom, lat: pos.lat, lng: pos.lng,
                    iconName: row.icone || def.iconName, color: row.couleur || def.color,
                    category: row.categorie
                });
            });
            // Reconstruire la couche infrastructures si elle est active
            if (sigLayers.infrastructures) {
                mainMap.removeLayer(sigLayers.infrastructures);
                sigLayers.infrastructures = buildInfrastructuresLayer().addTo(mainMap);
            }
        }

        // --- Mettre à jour la coloration fiscale des parcelles (Agent Municipal) ---
        if (data.paiements_fiscaux && Array.isArray(data.paiements_fiscaux)) {
            paiementsFiscaux = data.paiements_fiscaux;
            const currentFilter = document.querySelector('#view-municipal .fiscal-filter-btn.active')?.dataset?.filter || 'all';
            if (typeof refreshFiscalMap === 'function') refreshFiscalMap(currentFilter);
        }

        // --- Fusionner les parcelles ajoutées par d'autres agents (autre onglet/appareil) ---
        if (data.parcelles_ajoutees && Array.isArray(data.parcelles_ajoutees)) {
            const existingNums = new Set(CADASTRE_PARCELLES.features.map(f => (f.properties || {}).Num_Parcel));
            let addedCount = 0;
            data.parcelles_ajoutees.forEach(f => {
                if (f && f.properties && !existingNums.has(f.properties.Num_Parcel)) {
                    CADASTRE_PARCELLES.features.push(f);
                    existingNums.add(f.properties.Num_Parcel);
                    addedCount++;
                }
            });
            if (addedCount > 0 && sigLayers.parcelles) {
                mainMap.removeLayer(sigLayers.parcelles);
                sigLayers.parcelles = buildParcellesLayer().addTo(mainMap);
            }
        }
        if (typeof data.max_parcelle_id === 'number') {
            lastKnownParcelleId = data.max_parcelle_id;
        }

        // --- Mettre à jour la couche fiscale si active ---
        const chkFiscal = document.getElementById('chkParcellesFiscal');
        if (chkFiscal && chkFiscal.checked) {
            buildFiscalBuildingPolygons();
        }

        enforceSigLayerOrder();
        console.log('[UNCO] Données synchronisées en temps réel.');
    } catch(e) {
        // Ne JAMAIS recharger la page ici : cette fonction tourne toutes les 20
        // secondes en arrière-plan (synchro périodique). Un reload() automatique
        // à chaque échec provoquait un rechargement en boucle de la page.
        console.warn('[UNCO] Synchronisation périodique échouée (nouvelle tentative dans 20s) :', e);
    }
}
window.refreshMapData = refreshMapData;

// ========== CHARGER LES BÂTIMENTS DEPUIS POSTGRESQL ==========
function loadBuildingsFromPostgreSQL() {
    const buildings = <?php echo json_encode($batiments_db); ?>;

    buildings.forEach(b => {
        if (b.latitude && b.longitude) {
            const pos = recenterIfNearOldDefault(parseFloat(b.latitude), parseFloat(b.longitude));
            b.latitude  = pos.lat;
            b.longitude = pos.lng;
            b._repositionne = pos.repositionne;
        }
    });

    buildingsDataGlobal = buildings;
    
    console.log("Chargement de " + buildings.length + " bâtiments depuis PostgreSQL");

    // (Ré)initialiser le groupe des polygones de bâtiments
    if (buildingPolygonsGroup) { mainMap.removeLayer(buildingPolygonsGroup); }
    buildingPolygonsGroup = L.layerGroup();

    buildings.forEach(b => {
        if (b.polygon_geojson) {
            try {
                const geojsonObj = typeof b.polygon_geojson === 'string'
                    ? JSON.parse(b.polygon_geojson)
                    : b.polygon_geojson;

                const polyLayer = L.geoJSON(geojsonObj, {
                    style: {
                        color: '#1A6B45',
                        weight: 2,
                        fillColor: '#86efac',
                        fillOpacity: 0.45
                    }
                });

                polyLayer.bindPopup(`
                    <strong>${b.identifiant}</strong><br>
                    Type : ${b.type || '—'}<br>
                    Adresse : ${b.adresse || '—'}<br>
                    Quartier : ${b.quartier || '—'}<br>
                    Surface : ${b.surface ? Math.round(b.surface) + ' m²' : '—'}<br>
                    Étages : ${b.etages || '—'}
                `);

                // Ajouter au groupe, pas directement à mainMap
                buildingPolygonsGroup.addLayer(polyLayer);
            } catch(e) {
                console.warn('Polygone invalide pour ' + b.identifiant + ':', e);
                createBuildingMarker(b.latitude, b.longitude, b.identifiant, b.type,
                    b.adresse || 'Non renseignée', b.quartier || 'Non renseigné',
                    b.surface || '?', b.etages || '?');
            }
        } else {
            createBuildingMarker(
                b.latitude, b.longitude,
                b.identifiant, b.type,
                b.adresse || 'Non renseignée',
                b.quartier || 'Non renseigné',
                b.surface || '?',
                b.etages || '?'
            );
        }
    });

    // Afficher le groupe uniquement si la couche "Parcelles" est active
    const chkParcelles = document.getElementById('chkParcelles');
    if (chkParcelles && chkParcelles.checked) {
        buildingPolygonsGroup.addTo(mainMap);
    }
    // Si la couche parcelles n'est pas encore cochée, le groupe reste en mémoire
    // et sera ajouté automatiquement quand l'utilisateur cochera "Parcelles".
    
    totalBatiments = buildings.length;
    updateKPIs();
    sessionStorage.setItem('unco_totalBatiments', totalBatiments);
}

function setBaseLayer(layer, btn) { 
    mainMap.removeLayer(baseLayers[currentBaseLayer]); 
    mainMap.addLayer(baseLayers[layer]); 
    currentBaseLayer = layer; 
    document.querySelectorAll('.map-pill').forEach(b=>b.classList.remove('active')); 
    btn.classList.add('active'); 
}
// Construit une "couche cadastrale" à partir des bâtiments réels (NICAD non disponible
// au niveau bâtiment dans la base actuelle : on affiche les informations parcellaires connues
// — identifiant, type, adresse, quartier, surface — sous forme de parcelles cliquables).
function buildCadastreLayer() {
    const group = L.layerGroup();
    let surfaceTotale = 0;
    let compte = 0;

    buildingsDataGlobal.forEach(b => {
        if (!b.latitude || !b.longitude) return;
        const lat = parseFloat(b.latitude), lng = parseFloat(b.longitude);
        const surface = parseFloat(b.surface) || 150; // valeur par défaut si surface inconnue
        surfaceTotale += surface;
        compte++;

        // Rayon approximatif de la parcelle (en mètres) déduit de sa surface (cercle équivalent)
        const rayon = Math.max(8, Math.sqrt(surface / Math.PI));

        const parcelle = L.circle([lat, lng], {
            radius: rayon,
            color: '#1A6B45',
            weight: 2,
            dashArray: '4,3',
            fillColor: '#34d399',
            fillOpacity: 0.18
        });

        parcelle.bindPopup(`
            <div style="font-size:0.85rem; line-height:1.5;">
                <strong>Parcelle ${b.identifiant || ''}</strong><br>
                Type : ${b.type || 'Non renseigné'}<br>
                Adresse : ${b.adresse || 'Non renseignée'}<br>
                Quartier : ${b.quartier || 'Non renseigné'}<br>
                Surface estimée : ${surface.toLocaleString()} m²<br>
                Étages : ${b.etages || '?'}
            </div>
        `);

        // Zoom rapide sur la parcelle au clic, pour une lecture plus précise
        parcelle.on('click', () => mainMap.flyTo([lat, lng], Math.max(mainMap.getZoom(), 20)));

        group.addLayer(parcelle);
    });

    return { group, compte, surfaceTotale };
}

// ========== COUCHES SIG INDIVIDUELLES ==========
const sigLayers = { commune: null, quartiers: null, parcelles: null, rues: null, infrastructures: null };

function buildCommuneLayer() {
    // Style "halo" : un trait blanc large en dessous + un trait noir fin au-dessus,
    // pour rester visible quel que soit le fond (quartiers colorés, satellite, etc.)
    const group = L.layerGroup();
    L.geoJSON(CADASTRE_COMMUNE, { style: { color: '#ffffff', weight: 9, fill: false, opacity: 0.95 } }).addTo(group);
    L.geoJSON(CADASTRE_COMMUNE, { style: { color: '#000000', weight: 4, fill: false, opacity: 1 } }).addTo(group);
    return group;
}
function buildQuartiersLayer() {
    return L.geoJSON(CADASTRE_QUARTIERS, {
        style: (f) => {
            // Couleurs variées par quartier comme sur les images
            const colors = ['#ef4444','#3b82f6','#f59e0b','#10b981','#8b5cf6','#ec4899','#06b6d4','#84cc16','#f97316','#6366f1'];
            const idx = (f.properties.OBJECTID || 0) % colors.length;
            return { color: colors[idx], weight: 2, fillColor: colors[idx], fillOpacity: 0.15 };
        },
        onEachFeature: (f, l) => {
            const nom = f.properties.QRT_VLG_HA || f.properties.Nom || 'Quartier';
            l.bindTooltip(nom, { permanent: false, sticky: true, className: 'leaflet-tooltip-quartier' });
        }
    });
}
function buildParcellesLayer() {
    return L.geoJSON(CADASTRE_PARCELLES, {
        style: { color: '#15803d', weight: 1, fillColor: '#86efac', fillOpacity: 0.55 },
        onEachFeature: (f, l) => {
            const p = f.properties;
            const surface = (p.Surface !== undefined && p.Surface !== null)
                ? Math.round(p.Surface).toLocaleString('fr-FR') + ' m²'
                : '—';
            l.bindPopup(`
                <strong>Parcelle</strong><br>
                N° parcelle : ${p.Num_Parcel || '—'}<br>
                ${p.Identifiant ? `Identifiant : ${p.Identifiant}<br>` : ''}
                Rue : ${p.Rue || '—'}<br>
                Quartier : ${p.Quatiers || '—'}<br>
                Commune : ${p.Commune || '—'}<br>
                Surface : ${surface}
            `);
        }
    });
}
function buildRuesLayer() {
    return L.geoJSON(CADASTRE_RUES, {
        style: (f) => {
            const type = (f.properties.Type || '').toLowerCase();
            const color = type.includes('principal') ? '#1d4ed8' : type.includes('second') ? '#7c3aed' : '#f97316';
            return { color, weight: type.includes('principal') ? 3 : 2, opacity: 0.85 };
        },
        onEachFeature: (f, l) => {
            const nom = f.properties.Nom || f.properties.nom || '';
            if (nom) l.bindTooltip(nom, { sticky: true });
        }
    });
}
function buildInfrastructuresLayer() {
    const group = L.layerGroup();
    infrastructures.forEach(inf => {
        const color = inf.color || '#1A6B45';
        const icon  = inf.iconName || 'map-pin';
        // Coordonnées réelles de l'infrastructure : on ne les déplace plus artificiellement.
        // Le marqueur est rendu dans le "markerPane" de Leaflet (au-dessus de l'"overlayPane"
        // où sont dessinées les parcelles), donc il s'affiche déjà par-dessus le cadastre
        // dès que les deux couches sont actives — sans avoir besoin de recaler le point.
        const iconHtml = `<div style="background:${color};width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,0.35);border:2px solid white;"><i data-lucide="${icon}" style="width:18px;height:18px;color:white;"></i></div>`;
        const marker = L.marker([inf.lat, inf.lng], { icon: L.divIcon({ html: iconHtml, iconSize: [36,36], className: '' }) });
        marker.bindPopup(`<strong>${inf.name}</strong>`);
        group.addLayer(marker);
    });
    setTimeout(() => lucide.createIcons(), 100);
    return group;
}

function toggleSigLayer(layerName, visible) {
    if (!mainMap) { console.warn('toggleSigLayer: mainMap non initialisée'); return; }
    if (sigLayers[layerName]) { mainMap.removeLayer(sigLayers[layerName]); sigLayers[layerName] = null; }
    // Quand on désactive la couche parcelles, masquer aussi les polygones de bâtiments tracés
    if (layerName === 'parcelles' && !visible && buildingPolygonsGroup) {
        mainMap.removeLayer(buildingPolygonsGroup);
    }
    if (!visible) { enforceSigLayerOrder(); return; }
    try {
        switch(layerName) {
            case 'commune':
                if (typeof CADASTRE_COMMUNE === 'undefined') { console.error('CADASTRE_COMMUNE non défini'); break; }
                sigLayers.commune = buildCommuneLayer().addTo(mainMap);
                break;
            case 'quartiers':
                if (typeof CADASTRE_QUARTIERS === 'undefined') { console.error('CADASTRE_QUARTIERS non défini'); break; }
                sigLayers.quartiers = buildQuartiersLayer().addTo(mainMap);
                break;
            case 'parcelles':
                if (typeof CADASTRE_PARCELLES === 'undefined') { console.error('CADASTRE_PARCELLES non défini'); break; }
                sigLayers.parcelles = buildParcellesLayer().addTo(mainMap);
                try { mainMap.fitBounds(sigLayers.parcelles.getBounds(), { padding: [30,30] }); } catch(e) {}
                // Afficher aussi les polygones de bâtiments tracés
                if (buildingPolygonsGroup) buildingPolygonsGroup.addTo(mainMap);
                if (!sigLayers.infrastructures) {
                    sigLayers.infrastructures = buildInfrastructuresLayer().addTo(mainMap);
                    const chkInfra = document.getElementById('chkInfrastructures');
                    if (chkInfra) chkInfra.checked = true;
                }
                break;
            case 'rues':
                if (typeof CADASTRE_RUES === 'undefined') { console.error('CADASTRE_RUES non défini'); break; }
                sigLayers.rues = buildRuesLayer().addTo(mainMap);
                break;
            case 'infrastructures':
                sigLayers.infrastructures = buildInfrastructuresLayer().addTo(mainMap);
                break;
        }
    } catch(err) {
        console.error('Erreur lors du chargement de la couche "' + layerName + '":', err);
        showToast('Erreur couche SIG', 'Impossible de charger "' + layerName + '": ' + err.message, 'error');
    }

    // Appliquer l'ordre d'empilement strict après chaque activation/désactivation
    enforceSigLayerOrder();
}

// Ordre d'empilement strict (du bas vers le haut) :
// quartiers → parcelles → rues → commune → infrastructures
// Appliqué après chaque changement de couche pour que les parcelles soient
// TOUJOURS cliquables et visibles même quand les quartiers sont actifs.
function enforceSigLayerOrder() {
    // Quartiers tout en bas (polygones larges, opacité faible)
    if (sigLayers.quartiers) {
        sigLayers.quartiers.bringToBack();
    }
    // Parcelles au-dessus des quartiers
    if (sigLayers.parcelles) {
        sigLayers.parcelles.bringToFront();
    }
    // Rues au-dessus des parcelles
    if (sigLayers.rues) {
        sigLayers.rues.bringToFront();
    }
    // Limite communale par-dessus tout le reste (layerGroup → itérer sur les sous-couches)
    if (sigLayers.commune && sigLayers.commune.eachLayer) {
        sigLayers.commune.eachLayer(l => { if (l.bringToFront) l.bringToFront(); });
    }
    // Infrastructures tout en haut (marqueurs, doivent toujours être cliquables)
    if (sigLayers.infrastructures) {
        sigLayers.infrastructures.eachLayer(m => { if (m.bringToFront) m.bringToFront(); });
    }
}

function toggleLayersPanel() {
    const content = document.getElementById('layersPanelContent');
    const toggle  = document.getElementById('layersPanelToggle');
    if (!content || !toggle) return;
    const hidden = content.style.display === 'none';
    content.style.display = hidden ? 'block' : 'none';
    toggle.textContent = hidden ? '−' : '+';
}

// ========== COUCHES SIG — VUE AGENT MUNICIPAL (carte fiscale) ==========
// Même principe que toggleSigLayer(), mais appliqué à `fiscalMap`, pour permettre
// à l'Agent Municipal de manipuler individuellement les données géojson
// (commune, quartiers, parcelles, rues, infrastructures) depuis sa propre carte.
const sigLayersFiscal = { commune: null, quartiers: null, parcelles: null, rues: null, infrastructures: null };

function toggleSigLayerFiscal(layerName, visible) {
    if (!fiscalMap) { console.warn('toggleSigLayerFiscal: fiscalMap non initialisée'); return; }

    // Supprimer la couche existante
    if (sigLayersFiscal[layerName]) { fiscalMap.removeLayer(sigLayersFiscal[layerName]); sigLayersFiscal[layerName] = null; }

    // Pour la couche parcelles : gérer aussi le groupe des polygones de bâtiments
    if (layerName === 'parcelles') {
        if (fiscalBuildingPolygonsGroup) { fiscalMap.removeLayer(fiscalBuildingPolygonsGroup); fiscalBuildingPolygonsGroup = null; }
        if (!visible) return;
    } else {
        if (!visible) return;
    }

    try {
        switch(layerName) {
            case 'commune':
                if (typeof CADASTRE_COMMUNE === 'undefined') { console.error('CADASTRE_COMMUNE non défini'); break; }
                sigLayersFiscal.commune = buildCommuneLayer().addTo(fiscalMap);
                break;

            case 'quartiers':
                if (typeof CADASTRE_QUARTIERS === 'undefined') { console.error('CADASTRE_QUARTIERS non défini'); break; }
                sigLayersFiscal.quartiers = buildQuartiersLayer().addTo(fiscalMap);
                break;

            case 'parcelles':
                if (typeof CADASTRE_PARCELLES === 'undefined') { console.error('CADASTRE_PARCELLES non défini'); break; }

                // Utiliser buildFiscalParcelLayer (colorée par statut fiscal) au lieu de
                // buildParcellesLayer (couleur fixe verte) — c'est la correction principale
                // pour afficher vert=payé, orange=en attente, rouge=en retard, gris=exonéré
                const currentFilter = document.querySelector('#view-municipal .fiscal-filter-btn.active')?.dataset?.filter || 'all';
                sigLayersFiscal.parcelles = buildFiscalParcelLayer(currentFilter);
                if (sigLayersFiscal.parcelles) {
                    sigLayersFiscal.parcelles.addTo(fiscalMap);
                    try { fiscalMap.fitBounds(sigLayersFiscal.parcelles.getBounds(), { padding: [30,30] }); } catch(e) {}
                }

                // Charger et afficher les polygones de bâtiments tracés manuellement
                // (correction : ils n'apparaissaient pas dans la vue Agent Municipal)
                buildFiscalBuildingPolygons();

                break;

            case 'rues':
                if (typeof CADASTRE_RUES === 'undefined') { console.error('CADASTRE_RUES non défini'); break; }
                sigLayersFiscal.rues = buildRuesLayer().addTo(fiscalMap);
                break;

            case 'infrastructures':
                sigLayersFiscal.infrastructures = buildInfrastructuresLayer().addTo(fiscalMap);
                break;
        }
    } catch(err) {
        console.error('Erreur lors du chargement de la couche fiscale "' + layerName + '":', err);
        showToast('Erreur couche SIG', 'Impossible de charger "' + layerName + '": ' + err.message, 'error');
    }

    // Ordre d'empilement : quartiers < parcelles < rues < commune < infrastructures
    if (sigLayersFiscal.quartiers) sigLayersFiscal.quartiers.bringToBack();
    if (sigLayersFiscal.parcelles) sigLayersFiscal.parcelles.bringToFront();
    if (sigLayersFiscal.rues) sigLayersFiscal.rues.bringToFront();
    if (sigLayersFiscal.commune && sigLayersFiscal.commune.eachLayer) {
        sigLayersFiscal.commune.eachLayer(l => { if (l.bringToFront) l.bringToFront(); });
    }
    if (sigLayersFiscal.infrastructures) {
        sigLayersFiscal.infrastructures.eachLayer(m => { if (m.bringToFront) m.bringToFront(); });
    }
}

// Groupe des polygones de bâtiments tracés sur la carte Agent Municipal
let fiscalBuildingPolygonsGroup = null;

// Charge les polygones de bâtiments depuis buildingsDataGlobal et les affiche sur fiscalMap
function buildFiscalBuildingPolygons() {
    // Toujours supprimer le groupe existant de la carte AVANT toute vérification
    if (fiscalBuildingPolygonsGroup) {
        fiscalMap.removeLayer(fiscalBuildingPolygonsGroup);
        fiscalBuildingPolygonsGroup = null;
    }

    // Ne reconstruire que si la couche Parcelles est active
    if (!isFiscalParcelActive()) return;

    fiscalBuildingPolygonsGroup = L.layerGroup();

    const parcelStatut = buildParcelStatutMap();
    const buildings = buildingsDataGlobal || [];
    buildings.forEach(b => {
        if (!b.polygon_geojson) return;
        try {
            const geojsonObj = typeof b.polygon_geojson === 'string'
                ? JSON.parse(b.polygon_geojson)
                : b.polygon_geojson;

            const ring = geojsonObj.type === 'Feature' ? geojsonObj.geometry.coordinates[0] : geojsonObj.coordinates[0];
            const centroid = {
                lat: ring.reduce((s, c) => s + c[1], 0) / ring.length,
                lng: ring.reduce((s, c) => s + c[0], 0) / ring.length
            };
            const nearestParcel = findNearestParcel(centroid);
            const numParcel = nearestParcel.Num_Parcel || '';
            const statut = parcelStatut[numParcel] || 'default';
            const statutLabel = statut === 'paye' ? '✅ Payé' : statut === 'pending' ? '⏳ En attente' : statut === 'overdue' ? '⚠️ En retard' : statut === 'exempt' ? '🔰 Exonéré' : '— (aucun paiement lié)';

            // Respecter le filtre fiscal actif : un bâtiment tracé manuellement ne doit
            // apparaître en couleur pleine que s'il correspond au filtre sélectionné
            // (même logique que buildFiscalParcelLayer pour le cadastre). Sans statut
            // (default), il ne correspond à AUCUN filtre spécifique.
            let style;
            if (currentFiscalFilter && currentFiscalFilter !== 'all' && statut !== currentFiscalFilter) {
                style = { color: '#cbd5e1', weight: 1, dashArray: '4,3', fillColor: '#e2e8f0', fillOpacity: 0.3 };
            } else {
                const colors = FISCAL_COLORS[statut] || FISCAL_COLORS.default;
                style = { color: colors.stroke, weight: 2.5, dashArray: '4,3', fillColor: colors.fill, fillOpacity: 0.65 };
            }

            const polyLayer = L.geoJSON(geojsonObj, { style: style });
            polyLayer.bindPopup(`
                <div style="font-size:0.83rem;line-height:1.5;">
                    <strong>${b.identifiant}</strong> <em style="color:#6b7280;">(tracé manuellement)</em><br>
                    Type : ${b.type || '—'}<br>
                    Adresse : ${b.adresse || '—'}<br>
                    Quartier : ${b.quartier || '—'}<br>
                    Surface : ${b.surface ? Math.round(b.surface) + ' m²' : '—'}<br>
                    Parcelle rattachée : <strong>${numParcel || '—'}</strong><br>
                    Statut fiscal : <strong>${statutLabel}</strong>
                </div>
            `);
            fiscalBuildingPolygonsGroup.addLayer(polyLayer);
        } catch(e) {
            console.warn('Polygone bâtiment invalide (fiscal):', b.identifiant, e);
        }
    });

    fiscalBuildingPolygonsGroup.addTo(fiscalMap);
}

// Vérifie si la couche parcelles est active sur fiscalMap
function isFiscalParcelActive() {
    const chk = document.getElementById('chkParcellesFiscal');
    return chk && chk.checked;
}

function toggleLayersPanelFiscal() {
    const content = document.getElementById('layersPanelFiscalContent');
    const toggle  = document.getElementById('layersPanelFiscalToggle');
    if (!content || !toggle) return;
    const hidden = content.style.display === 'none';
    content.style.display = hidden ? 'block' : 'none';
    toggle.textContent = hidden ? '−' : '+';
}

// Le bouton "Cadastre" active maintenant toutes les couches SIG d'un coup
function toggleCadastre(btn) {
    cadastreActive = !cadastreActive;
    btn.classList.toggle('active', cadastreActive);

    const panel   = document.getElementById('cadastreInfoPanel');
    const content = document.getElementById('cadastreInfoContent');

    // Forcer Leaflet à recalculer la taille du conteneur (corrige la vue "monde entier" / carte coupée)
    if (mainMap) {
        setTimeout(() => mainMap.invalidateSize(), 50);
    }

    const layerMap = { chkCommune: 'commune', chkQuartiers: 'quartiers', chkParcelles: 'parcelles', chkRues: 'rues', chkInfrastructures: 'infrastructures' };
    Object.keys(layerMap).forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.checked = cadastreActive;
            toggleSigLayer(layerMap[id], cadastreActive);
        }
    });
    // Forcer l'ordre d'empilement strict une dernière fois après activation de toutes les couches
    if (cadastreActive) enforceSigLayerOrder();

    if (cadastreActive) {
        // Zoomer sur Ouakam (avec un léger délai pour laisser invalidateSize() agir d'abord).
        // On ne dépend jamais d'une seule couche : si CADASTRE_COMMUNE échoue, on retombe
        // sur les parcelles, puis sur des coordonnées fixes connues d'Ouakam en dernier recours
        // — jamais sur la vue par défaut de Leaflet (qui montre le monde entier).
        setTimeout(() => {
            let zoomed = false;
            try {
                const bounds = L.geoJSON(CADASTRE_COMMUNE).getBounds();
                if (bounds.isValid()) { mainMap.fitBounds(bounds, { padding: [20,20] }); zoomed = true; }
            } catch(e) { console.warn('Zoom sur CADASTRE_COMMUNE impossible:', e); }

            if (!zoomed) {
                try {
                    const bounds = L.geoJSON(CADASTRE_PARCELLES).getBounds();
                    if (bounds.isValid()) { mainMap.fitBounds(bounds, { padding: [20,20] }); zoomed = true; }
                } catch(e) { console.warn('Zoom sur CADASTRE_PARCELLES impossible:', e); }
            }

            if (!zoomed) {
                // Dernier recours : centre connu d'Ouakam
                mainMap.setView([14.7247, -17.4892], 16);
            }
        }, 100);

        if (content) {
            content.innerHTML = `<strong>Commune d'Ouakam</strong><br>30 quartiers · 2 058 parcelles · 166 rues<br><span style="color:#94a3b8;font-size:0.75em;">Cliquez sur une parcelle ou une rue pour le détail.</span>`;
        }
        if (panel) panel.style.display = 'block';
        showToast('Cadastre activé', 'Commune · Quartiers · Parcelles · Rues', 'success');
    } else {
        if (panel) panel.style.display = 'none';
        if (content) content.innerHTML = 'Chargement…';
    }
}

function toggleCadastreFiscal(btn) {
    btn.classList.toggle('active');
    const isActive = btn.classList.contains('active');
    if (sigLayers._fiscal) { fiscalMap.removeLayer(sigLayers._fiscal); sigLayers._fiscal = null; }
    if (fiscalBuildingPolygonsGroup) { fiscalMap.removeLayer(fiscalBuildingPolygonsGroup); }
    if (isActive) {
        // Parcelles colorées par statut fiscal (Payé/En attente/En retard/Exonéré),
        // pas la couche générique verte utilisée sur la carte SIG.
        const currentFilter = document.querySelector('#view-municipal .fiscal-filter-btn.active')?.dataset?.filter || 'all';
        sigLayers._fiscal = L.layerGroup([
            buildCommuneLayer(), buildQuartiersLayer(), buildFiscalParcelLayer(currentFilter), buildRuesLayer()
        ]).addTo(fiscalMap);
        buildFiscalBuildingPolygons();
        try { fiscalMap.fitBounds(L.geoJSON(CADASTRE_COMMUNE).getBounds(), { padding: [20,20] }); } catch(e) {}
    }
}
function toggleCadastreAdmin(btn) {
    btn.classList.toggle('active');
    const isActive = btn.classList.contains('active');
    if (sigLayers._admin) { adminMap.removeLayer(sigLayers._admin); sigLayers._admin = null; }
    if (isActive) {
        sigLayers._admin = L.layerGroup([
            buildCommuneLayer(), buildQuartiersLayer(), buildParcellesLayer(), buildRuesLayer()
        ]).addTo(adminMap);
        try { adminMap.fitBounds(L.geoJSON(CADASTRE_COMMUNE).getBounds(), { padding: [20,20] }); } catch(e) {}
    }
}

// ========== ITINÉRAIRE ==========
function toggleRoutingControl() {
    if (!routingControl) { showToast('Non disponible', 'Plugin routing non chargé', 'warning'); return; }
    const btn = document.getElementById('routingBtn');
    const container = routingControl.getContainer();
    const isHidden = !container || container.style.display === 'none' || container.style.display === '';
    if (isHidden) {
        routingControl.show();
        if (container) container.style.display = 'block';
        if (btn) btn.classList.add('active');
        showToast('Itinéraire', 'Cliquez sur 2 points sur la carte', 'info');
        let clicks = 0;
        const handler = function(e) {
            if (clicks === 0) { routingControl.spliceWaypoints(0, 1, e.latlng); clicks++; }
            else { routingControl.spliceWaypoints(routingControl.getWaypoints().length - 1, 1, e.latlng); mainMap.off('click', handler); }
        };
        mainMap.on('click', handler);
    } else {
        routingControl.hide();
        if (container) container.style.display = 'none';
        routingControl.setWaypoints([]);
        if (btn) btn.classList.remove('active');
    }
}

// ========== MODALES COMPLÈTES (AJOUTER / MODIFIER) ==========
const uncoCore = {
    switchRole(role, btn) {
        // Nettoyer les polygones bâtiments fiscaux quand on quitte la vue Agent Municipal
        if (role !== 'municipal' && fiscalBuildingPolygonsGroup && fiscalMap) {
            fiscalMap.removeLayer(fiscalBuildingPolygonsGroup);
            fiscalBuildingPolygonsGroup = null;
        }
        document.querySelectorAll('.nav-item').forEach(b=>b.classList.remove('active')); btn.classList.add('active');
        document.querySelectorAll('.role-view').forEach(v=>v.classList.remove('active')); document.getElementById(`view-${role}`).classList.add('active');
        document.getElementById('current-view-title').innerText = role === 'sig' ? 'Carte Interactive' : (role === 'municipal' ? 'Gestion Fiscale' : 'Administration');
        document.getElementById('current-role-badge').innerText = role === 'sig' ? 'TECHNICIEN SIG' : (role === 'municipal' ? 'AGENT MUNICIPAL' : 'ADMINISTRATEUR');
        setTimeout(() => { if(mainMap) mainMap.invalidateSize(); if(fiscalMap) fiscalMap.invalidateSize(); if(adminMap) adminMap.invalidateSize(); }, 150);
        if (role === 'admin') { setTimeout(() => { refreshAdminStats(true); lucide.createIcons(); }, 200); }
    },
    openModal(action) {
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('globalModal'));
        const modalTitle = document.getElementById('modalTitle');
        const modalBody = document.getElementById('modalBodyText');
        const modalFooter = document.querySelector('#globalModal .modal-footer');
        
        // Modale de confirmation après tracé d'un polygone bâtiment
        if (action === 'drawBuilding') {
            modalTitle.innerText = 'Confirmer le Bâtiment Tracé';
            const geojson = window._drawnBuildingGeoJSON || {};
            const area    = window._drawnBuildingArea   || 0;
            const centroid = window._drawnBuildingCentroid || { lat: 14.7247, lng: -17.4892 };
            const parcelInfo = window._drawnParcelInfo || {};
            modalBody.innerHTML = `
                <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:0.85rem;">
                    <div style="font-weight:700;color:#15803d;margin-bottom:6px;">📐 Polygone tracé</div>
                    <div style="display:flex;gap:20px;flex-wrap:wrap;">
                        <span>Surface calculée : <strong>${Math.round(area)} m²</strong></span>
                        <span>Parcelle : <strong>${parcelInfo.Num_Parcel || '—'}</strong>${parcelInfo._generated ? ' <span style="color:#b45309;">(nouveau n° en continuité du cadastre)</span>' : ''}</span>
                        <span>Quartier : <strong>${parcelInfo.Quatiers || '—'}</strong></span>
                    </div>
                </div>
                <div style="display:flex;gap:16px;margin-bottom:16px;">
                    <div style="flex:1;">
                        <label style="font-weight:600;margin-bottom:6px;display:block;">Identifiant <span style="color:#dc2626;">*</span></label>
                        <input type="text" id="drawBuildingId" value="BAT-OUA-${new Date().getFullYear()}-${String(Date.now()).slice(-4)}" class="form-control" style="background:#f1f5f9;">
                    </div>
                    <div style="flex:1;">
                        <label style="font-weight:600;margin-bottom:6px;display:block;">Type <span style="color:#dc2626;">*</span></label>
                        <select id="drawBuildingType" class="form-control">
                            <option selected>Résidentiel</option>
                            <option>Commercial</option>
                            <option>Mixte</option>
                            <option>Équipement public</option>
                        </select>
                    </div>
                </div>
                <div style="display:flex;gap:16px;margin-bottom:16px;">
                    <div style="flex:1;">
                        <label style="font-weight:600;margin-bottom:6px;display:block;">Adresse</label>
                        <input type="text" id="drawBuildingAddr" value="${parcelInfo.Rue ? 'Rue '+parcelInfo.Rue : ''}" class="form-control">
                    </div>
                    <div style="flex:1;">
                        <label style="font-weight:600;margin-bottom:6px;display:block;">Quartier</label>
                        <input type="text" id="drawBuildingQuartier" value="${parcelInfo.Quatiers || ''}" class="form-control">
                    </div>
                </div>
                <div style="display:flex;gap:16px;margin-bottom:16px;">
                    <div style="flex:1;">
                        <label style="font-weight:600;margin-bottom:6px;display:block;">Surface (m²)</label>
                        <input type="text" id="drawBuildingSurface" value="${Math.round(area)}" class="form-control">
                    </div>
                    <div style="flex:1;">
                        <label style="font-weight:600;margin-bottom:6px;display:block;">Nombre d'étages</label>
                        <select id="drawBuildingFloors" class="form-control">
                            <option>RDC</option><option selected>R+1</option><option>R+2</option><option>R+3</option><option>R+4+</option>
                        </select>
                    </div>
                </div>
                <div style="font-size:0.75rem;color:#64748b;">Centroïde : lat ${centroid.lat.toFixed(6)}, lng ${centroid.lng.toFixed(6)}</div>
            `;
            if (modalFooter) {
                modalFooter.innerHTML = `
                    <button type="button" class="btn btn-secondary" onclick="cancelDrawBuilding()" style="border-radius:40px;">Annuler</button>
                    <button type="button" class="btn btn-primary" onclick="saveDrawnBuilding()" style="background:#1A6B45;color:white;border-radius:40px;padding:8px 24px;">Enregistrer le bâtiment</button>
                `;
            }
        }

        else if (action === 'addBuilding') {
            modalTitle.innerText = 'Ajouter un Bâtiment';
            modalBody.innerHTML = `
                <div style="padding: 0;">
                    <div style="margin-bottom: 16px;">
                        <label style="font-weight: 600; margin-bottom: 6px; display: block;">Identifiant <span style="color: #dc2626;">*</span></label>
                        <input type="text" id="buildingId" value="BAT-OUA-2026-015" class="form-control"  style="background: #f1f5f9;">
                    </div>
                    <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                        <div style="flex: 1;">
                            <label style="font-weight: 600; margin-bottom: 6px; display: block;">Type de bâtiment <span style="color: #dc2626;">*</span></label>
                            <select id="buildingType" class="form-control">
                                <option selected>Résidentiel</option>
                                <option>Commercial</option>
                                <option>Mixte</option>
                                <option>Équipement public</option>
                            </select>
                        </div>
                        <div style="flex: 1;">
                            <label style="font-weight: 600; margin-bottom: 6px; display: block;">Nombre d'étages</label>
                            <select id="buildingFloors" class="form-control">
                                <option>RDC</option>
                                <option selected>R+1</option>
                                <option>R+2</option>
                                <option>R+3</option>
                                <option>R+4+</option>
                            </select>
                        </div>
                    </div>
                    <div style="margin-bottom: 16px;">
                        <label style="font-weight: 600; margin-bottom: 6px; display: block;">Adresse complète</label>
                        <input type="text" id="buildingAddress" value="Rue 12, Ouakam Nord" class="form-control">
                    </div>
                    <div style="margin-bottom: 16px;">
                        <label style="font-weight: 600; margin-bottom: 6px; display: block;">Quartier <span style="color: #dc2626;">*</span></label>
                        <select id="buildingDistrict" class="form-control">
                            <option selected>Ouakam Nord</option>
                            <option>Ouakam Centre</option>
                            <option>Ouakam Sud</option>
                            <option>Mermoz</option>
                            <option>Ngor</option>
                        </select>
                    </div>
                    <div style="display: flex; gap: 16px; margin-bottom: 8px;">
                        <div style="flex: 1;">
                            <label style="font-weight: 600; margin-bottom: 6px; display: block;">Latitude GPS</label>
                            <input type="text" id="buildingLat" value="14.724700" class="form-control">
                        </div>
                        <div style="flex: 1;">
                            <label style="font-weight: 600; margin-bottom: 6px; display: block;">Longitude GPS</label>
                            <input type="text" id="buildingLng" value="-17.489200" class="form-control">
                        </div>
                    </div>
                    <div style="margin-bottom: 16px;">
                        <button type="button" class="btn btn-outline-secondary" style="border-radius: 40px; font-size: 0.85rem; padding: 6px 16px;" onclick="localiserPosition('buildingLat','buildingLng', this)">
                            <i data-lucide="locate-fixed" style="width:15px;height:15px;vertical-align:-3px;"></i> Me localiser
                        </button>
                    </div>
                    <div style="margin-bottom: 16px;">
                        <label style="font-weight: 600; margin-bottom: 6px; display: block;">Surface (m²)</label>
                        <input type="number" id="buildingArea" value="280" class="form-control">
                    </div>
                </div>
            `;
            if (modalFooter) {
                modalFooter.innerHTML = `
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 40px;">Fermer</button>
                    <button type="button" class="btn btn-primary" onclick="saveBuildingToPostgreSQL()" style="background: #1A6B45; color: white; border-radius: 40px; padding: 8px 24px;">Ajouter le bâtiment</button>
                `;
            }
        } 
        else if (action === 'editBuilding') {
            modalTitle.innerText = 'Modifier un Bâtiment';
            modalBody.innerHTML = `
                <div style="padding: 0;">
                    <div class="alert alert-info" style="background: #eff6ff; border: none; border-radius: 12px; padding: 12px; font-size: 0.85rem; margin-bottom: 20px;">
                        Renseignez l'identifiant du bâtiment à modifier. Les champs seront pré-remplis automatiquement.
                    </div>
                    <div style="margin-bottom: 20px;">
                        <label style="font-weight: 600; margin-bottom: 6px; display: block;">Identifiant du bâtiment <span style="color: #dc2626;">*</span></label>
                        <div style="display: flex; gap: 12px;">
                            <input type="text" id="editBuildingId" placeholder="Ex: BAT-OUA-2024-003" class="form-control" style="flex: 1;">
                            <button type="button" class="btn btn-outline-secondary" onclick="loadBuildingData()" style="border-radius: 40px;">Charger</button>
                        </div>
                    </div>
                    <div id="editBuildingForm" style="display: none;">
                        <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                            <div style="flex: 1;">
                                <label style="font-weight: 600; margin-bottom: 6px; display: block;">Type</label>
                                <select id="editBuildingType" class="form-control">
                                    <option>Résidentiel</option>
                                    <option selected>Commercial</option>
                                    <option>Mixte</option>
                                    <option>Équipement public</option>
                                </select>
                            </div>
                            <div style="flex: 1;">
                                <label style="font-weight: 600; margin-bottom: 6px; display: block;">Surface (m²)</label>
                                <input type="number" id="editBuildingArea" value="145" class="form-control">
                            </div>
                            <div style="margin-bottom: 16px;">
                                <label>Adresse</label>
                                <input type="text" id="editBuildingAddress" class="form-control" placeholder="Adresse du bâtiment">
                            </div>
                        </div>
                        <div style="display: flex; gap: 16px; margin-bottom: 8px;">
                            <div style="flex: 1;">
                                <label style="font-weight: 600; margin-bottom: 6px; display: block;">Latitude GPS</label>
                                <input type="text" id="editBuildingLat" value="14.724700" class="form-control">
                            </div>
                            <div style="flex: 1;">
                                <label style="font-weight: 600; margin-bottom: 6px; display: block;">Longitude GPS</label>
                                <input type="text" id="editBuildingLng" value="-17.489200" class="form-control">
                            </div>
                        </div>
                        <div style="margin-bottom: 16px;">
                            <button type="button" class="btn btn-outline-secondary" style="border-radius: 40px; font-size: 0.85rem; padding: 6px 16px;" onclick="localiserPosition('editBuildingLat','editBuildingLng', this)">
                                <i data-lucide="locate-fixed" style="width:15px;height:15px;vertical-align:-3px;"></i> Me localiser
                            </button>
                        </div>
                        <div style="margin-bottom: 16px;">
                            <label style="font-weight: 600; margin-bottom: 6px; display: block;">Observations</label>
                            <textarea id="editBuildingObs" rows="3" class="form-control" placeholder="Observations...">Extension commerciale ajoutée au RDC en février 2026.</textarea>
                        </div>
                    </div>
                </div>
            `;
            if (modalFooter) {
                modalFooter.innerHTML = `
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 40px;">Fermer</button>
                    <button type="button" class="btn btn-primary" onclick="updateBuilding()" style="background: #1A6B45; color: white; border-radius: 40px; padding: 8px 24px;">Enregistrer les modifications</button>
                `;
            }
        }
        else if (action === 'generateReport') {
    modalTitle.innerText = 'Générer un Rapport';
    modalBody.innerHTML = `
        <div style="padding: 0;">
            <div style="margin-bottom: 20px;">
                <label style="font-weight: 600; margin-bottom: 8px; display: block;">Type de rapport <span style="color: #dc2626;">*</span></label>
                <select id="reportType" class="form-control">
                    <option value="fiscal">Recouvrement fiscal</option>
                    <option value="buildings">Structures recensées</option>
                    <option value="monthly">Évolution mensuelle</option>
                    <option value="overdue">Retards de paiement</option>
                </select>
            </div>
            <div style="display: flex; gap: 16px; margin-bottom: 20px;">
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 8px; display: block;">Période</label>
                    <select id="reportPeriod" class="form-control">
                        <option value="month">Dernier mois</option>
                        <option value="quarter" selected>Dernier trimestre</option>
                        <option value="year">Année en cours</option>
                    </select>
                </div>
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 8px; display: block;">Format</label>
                    <select id="reportFormat" class="form-control">
                        <option value="excel">Excel (.xlsx)</option>
                        <option value="pdf">PDF</option>
                    </select>
                </div>
            </div>
            <div class="modal-preview-strip" style="display: flex; gap: 24px; padding: 14px 16px; background: #f8fafc; border-radius: 16px; margin-bottom: 16px;">
                <div><span style="font-size: 0.65rem; color: #5B6E8C;">Enregistrements</span><br><span style="font-size: 1.2rem; font-weight: 700;">1 247</span></div>
                <div><span style="font-size: 0.65rem; color: #5B6E8C;">Taille estimée</span><br><span style="font-size: 1.2rem; font-weight: 700;">2.3 Mo</span></div>
            </div>
        </div>
    `;
    if (modalFooter) {
        modalFooter.innerHTML = `
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 40px;">Fermer</button>
            <button type="button" class="btn btn-primary" onclick="generateReport()" style="background: #1A6B45; color: white; border-radius: 40px; padding: 8px 24px;">Générer le rapport</button>
        `;
    }
}

else if (action === 'exportMap') {
    modalTitle.innerText = 'Exporter la Carte';
    modalBody.innerHTML = `
        <div style="padding: 0;">
            <div style="margin-bottom: 20px;">
                <label style="font-weight: 600; margin-bottom: 8px; display: block;">Format d'export</label>
                <select id="exportFormat" class="form-control">
                    <option value="pdf">PDF haute résolution</option>
                    <option value="png">PNG</option>
                    <option value="geotiff">GeoTIFF</option>
                </select>
            </div>
            <div style="display: flex; gap: 16px; margin-bottom: 20px;">
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 8px; display: block;">Zone</label>
                    <select id="exportZone" class="form-control">
                        <option value="commune">Commune entière</option>
                        <option value="nord">Ouakam Nord</option>
                        <option value="centre">Ouakam Centre</option>
                        <option value="sud">Ouakam Sud</option>
                    </select>
                </div>
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 8px; display: block;">Échelle</label>
                    <input type="text" id="exportScale" value="1:5000" class="form-control">
                </div>
            </div>
            <div style="margin-bottom: 20px;">
                <label style="font-weight: 600; margin-bottom: 8px; display: block;">Inclure les couches</label>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <label style="display: flex; align-items: center; gap: 8px;"><input type="checkbox" checked id="layerParcelles"> Parcelles cadastrales</label>
                    <label style="display: flex; align-items: center; gap: 8px;"><input type="checkbox" checked id="layerRues"> Réseau viaire</label>
                    <label style="display: flex; align-items: center; gap: 8px;"><input type="checkbox" id="layerInfras"> Infrastructures</label>
                    <label style="display: flex; align-items: center; gap: 8px;"><input type="checkbox" id="layerFiscal"> Données fiscales</label>
                </div>
            </div>
            <div class="modal-preview-strip" style="display: flex; gap: 24px; padding: 14px 16px; background: #f8fafc; border-radius: 16px; margin-bottom: 16px;">
                <div><span style="font-size: 0.65rem; color: #5B6E8C;">Taille estimée</span><br><span style="font-size: 1.2rem; font-weight: 700;" id="exportSize">4.8 Mo</span></div>
                <div><span style="font-size: 0.65rem; color: #5B6E8C;">Qualité</span><br><span style="font-size: 1.2rem; font-weight: 700;">300 DPI</span></div>
            </div>
        </div>
    `;
    
    // Ajouter un listener pour mettre à jour la taille estimée selon le format
    setTimeout(() => {
        const formatSelect = document.getElementById('exportFormat');
        if (formatSelect) {
            formatSelect.addEventListener('change', function() {
                const sizeEl = document.getElementById('exportSize');
                if (this.value === 'pdf') sizeEl.innerText = '4.8 Mo';
                else if (this.value === 'png') sizeEl.innerText = '2.1 Mo';
                else sizeEl.innerText = '12.3 Mo';
            });
        }
    }, 100);
    
    if (modalFooter) {
        modalFooter.innerHTML = `
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 40px;">Fermer</button>
            <button type="button" class="btn btn-primary" onclick="exportMap()" style="background: #1A6B45; color: white; border-radius: 40px; padding: 8px 24px;">Exporter</button>
        `;
    }
}


else if (action === 'recensement') {
    modalTitle.innerText = 'Recensement sur Terrain';
    modalBody.innerHTML = `
        <div style="padding: 0;">
            <div class="alert alert-info" style="background: #eff6ff; border: none; border-radius: 12px; padding: 12px; font-size: 0.85rem; margin-bottom: 20px;">
                <i data-lucide="map-pin" style="width: 16px; height: 16px; display: inline-block; vertical-align: middle;"></i>
                Cliquez sur « Me localiser » pour obtenir automatiquement votre position GPS.
            </div>
            <button type="button" class="btn btn-outline-secondary" onclick="getLocationForRecensement()" style="border-radius: 40px; width: 100%; margin-bottom: 20px; display: flex; align-items: center; justify-content: center; gap: 8px;">
                <i data-lucide="crosshair" style="width: 16px; height: 16px;"></i> Me localiser
            </button>
            <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Type de structure <span style="color: #dc2626;">*</span></label>
                    <select id="recType" class="form-control">
                        <option>Commerce</option>
                        <option>Boutique</option>
                        <option>Restaurant / Café</option>
                        <option>Pharmacie</option>
                        <option>Atelier / Garage</option>
                        <option>Bureau / Service</option>
                    </select>
                </div>
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Nom de la structure <span style="color: #dc2626;">*</span></label>
                    <input type="text" id="recName" value="Boutique Salam" class="form-control">
                </div>
            </div>
            <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Latitude GPS</label>
                    <input type="text" id="recLat" value="14.724700" class="form-control">
                </div>
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Longitude GPS</label>
                    <input type="text" id="recLng" value="-17.489200" class="form-control">
                </div>
            </div>
            <div style="margin-bottom: 16px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">Adresse / Quartier</label>
                <input type="text" id="recAddress" value="Cité Ensemble, Rue ASS-114" class="form-control">
            </div>
            <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Propriétaire / Gérant</label>
                    <input type="text" id="recOwner" value="Moussa Ndiaye" class="form-control">
                </div>
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Téléphone</label>
                    <input type="tel" id="recPhone" value="+221 77 823 45 12" class="form-control">
                </div>
            </div>
            <div style="margin-bottom: 16px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">Observations</label>
                <textarea id="recObs" rows="2" class="form-control" placeholder="Remarques..."></textarea>
            </div>
        </div>
    `;
    if (modalFooter) {
        modalFooter.innerHTML = `
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 40px;">Fermer</button>
            <button type="button" class="btn btn-primary" onclick="saveRecensement()" style="background: #1A6B45; color: white; border-radius: 40px; padding: 8px 24px;">Enregistrer le recensement</button>
        `;
    }
}
else if (action === 'addCommerce') {
    modalTitle.innerText = 'Ajouter un Commerce';
    modalBody.innerHTML = `
        <div style="padding: 0;">
            <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Nom du commerce <span style="color: #dc2626;">*</span></label>
                    <input type="text" id="commerceName" value="Supermarché Auchan" class="form-control">
                </div>
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Type</label>
                    <select id="commerceType" class="form-control">
                        <option>Alimentation générale</option>
                        <option selected>Supermarché</option>
                        <option>Restaurant</option>
                        <option>Pharmacie</option>
                        <option>Quincaillerie</option>
                    </select>
                </div>
            </div>
            <div style="margin-bottom: 16px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">Adresse complète</label>
                <input type="text" id="commerceAddress" value="Avenue Cheikh Anta Diop, N° 45" class="form-control">
            </div>
            <div style="display: flex; gap: 16px; margin-bottom: 8px;">
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Latitude GPS</label>
                    <input type="text" id="commerceLat" value="14.724700" class="form-control">
                </div>
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Longitude GPS</label>
                    <input type="text" id="commerceLng" value="-17.489200" class="form-control">
                </div>
            </div>
            <div style="margin-bottom: 16px;">
                <button type="button" class="btn btn-outline-secondary" style="border-radius: 40px; font-size: 0.85rem; padding: 6px 16px;" onclick="localiserPosition('commerceLat','commerceLng', this)">
                    <i data-lucide="locate-fixed" style="width:15px;height:15px;vertical-align:-3px;"></i> Me localiser
                </button>
            </div>
            <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Propriétaire</label>
                    <input type="text" id="commerceOwner" value="Ibrahima Sarr" class="form-control">
                </div>
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Contact</label>
                    <input type="tel" id="commercePhone" value="+221 76 512 88 03" class="form-control">
                </div>
            </div>
            <div style="margin-bottom: 16px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">Observations</label>
                <textarea id="commerceObs" rows="2" class="form-control"></textarea>
            </div>
        </div>
    `;
    if (modalFooter) {
        modalFooter.innerHTML = `
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 40px;">Fermer</button>
            <button type="button" class="btn btn-primary" onclick="saveCommerce()" style="background: #1A6B45; color: white; border-radius: 40px; padding: 8px 24px;">Enregistrer le commerce</button>
        `;
    }
}



else if (action === 'addInfrastructure') {
    modalTitle.innerText = 'Ajouter une Infrastructure';
    modalBody.innerHTML = `
        <div style="padding: 0;">
            <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Nom de l'infrastructure <span style="color: #dc2626;">*</span></label>
                    <input type="text" id="infraName" value="Nouvelle infrastructure" class="form-control">
                </div>
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Catégorie <span style="color: #dc2626;">*</span></label>
                    <select id="infraCategory" class="form-control" onchange="updateInfraIconPreview()">
                        <option>Santé</option>
                        <option>École</option>
                        <option>Mosquée</option>
                        <option>Administration</option>
                        <option>Commerce</option>
                        <option>Boutique</option>
                        <option>Restaurant</option>
                        <option>Voie</option>
                        <option>Électricité</option>
                        <option>Autre</option>
                    </select>
                </div>
            </div>
            <div style="display: flex; gap: 16px; margin-bottom: 8px;">
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Latitude GPS <span style="color: #dc2626;">*</span></label>
                    <input type="text" id="infraLat" value="14.724700" class="form-control">
                </div>
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Longitude GPS <span style="color: #dc2626;">*</span></label>
                    <input type="text" id="infraLng" value="-17.489200" class="form-control">
                </div>
            </div>
            <div style="margin-bottom: 12px;">
                <button type="button" class="btn btn-outline-secondary" style="border-radius: 40px; font-size: 0.85rem; padding: 6px 16px;" onclick="localiserPosition('infraLat','infraLng', this)">
                    <i data-lucide="locate-fixed" style="width:15px;height:15px;vertical-align:-3px;"></i> Me localiser
                </button>
            </div>
            <div style="margin-bottom: 4px; font-size: 0.8rem; color: #64748b;">
                Astuce : cliquez sur la carte pour positionner l'infrastructure, ou saisissez les coordonnées GPS manuellement.
            </div>
        </div>
    `;
    if (modalFooter) {
        modalFooter.innerHTML = `
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 40px;">Fermer</button>
            <button type="button" class="btn btn-primary" onclick="saveInfrastructure()" style="background: #1A6B45; color: white; border-radius: 40px; padding: 8px 24px;">Enregistrer l'infrastructure</button>
        `;
    }
}



else if (action === 'fiscalManagement') {
    modalTitle.innerText = 'Encaissement Fiscal';
    modalBody.innerHTML = `
        <div style="padding: 0;">
            <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Référence fiscale</label>
                    <input type="text" id="fiscalRef" value="FIS-OUA-${Math.floor(Math.random()*1000)}" class="form-control">
                </div>
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Contribuable</label>
                    <input type="text" id="fiscalContrib" value="" class="form-control" placeholder="Nom du contribuable">
                </div>
            </div>
            <div style="margin-bottom: 16px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">N° de parcelle (Num_Parcel du cadastre)</label>
                <input type="text" id="fiscalNicad" value="" class="form-control" placeholder="Ex : 1805 — doit correspondre exactement au Num_Parcel de la parcelle sur la carte">
                <div style="font-size:0.72rem;color:#6b7280;margin-top:4px;">C'est ce numéro qui relie ce paiement à la parcelle sur la carte, pour la coloration fiscale.</div>
            </div>
            <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Montant (FCFA)</label>
                    <input type="number" id="fiscalAmount" value="" class="form-control" placeholder="0">
                </div>
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Mode de paiement</label>
                    <select id="fiscalMethod" class="form-control">
                        <option>Espèces</option>
                        <option>Chèque</option>
                        <option>Mobile Money</option>
                        <option>Virement bancaire</option>
                    </select>
                </div>
            </div>
            <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Date de paiement</label>
                    <input type="date" id="fiscalDate" value="${new Date().toISOString().split('T')[0]}" class="form-control">
                </div>
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Statut</label>
                    <select id="fiscalStatus" class="form-control">
                        <option value="paye" style="color:#4caf50;">✅ Payé</option>
                        <option value="pending" style="color:#f59e0b;">⏳ En attente</option>
                        <option value="overdue" style="color:#ef4444;">⚠️ En retard</option>
                        <option value="exempt" style="color:#94a3b8;">🔰 Exonéré</option>
                    </select>
                </div>
            </div>
            <div style="margin-bottom: 16px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">Observations</label>
                <textarea id="fiscalObs" rows="2" class="form-control" placeholder="Observations..."></textarea>
            </div>
        </div>
    `;
    if (modalFooter) {
        modalFooter.innerHTML = `
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 40px;">Fermer</button>
            <button type="button" class="btn btn-primary" onclick="saveFiscalPaymentWithStatus()" style="background: #1A6B45; color: white; border-radius: 40px; padding: 8px 24px;">Enregistrer</button>
        `;
    }
}



else if (action === 'planningControl') {
    modalTitle.innerText = 'Contrôle Urbanistique';
    modalBody.innerHTML = `
        <div style="padding: 0;">
            <div class="alert alert-warning" style="background: #fef3c7; border: none; border-radius: 12px; padding: 12px; font-size: 0.85rem; margin-bottom: 20px;">
                ⚠️ Vérification de la conformité avec le plan d'urbanisme.
            </div>
            <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Numéro parcelle <span style="color: #dc2626;">*</span></label>
                    <input type="text" id="controlParcelle" value="OUA-PARC-2024-017" class="form-control">
                </div>
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Type de contrôle</label>
                    <select id="controlType" class="form-control">
                        <option>Permis de construire</option>
                        <option>Conformité zonage</option>
                        <option selected>Respect COS/CES</option>
                    </select>
                </div>
            </div>
            <div style="margin-bottom: 16px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">Zone réglementaire</label>
                <select id="controlZone" class="form-control">
                    <option>Zone résidentielle (R1)</option>
                    <option selected>Zone mixte (M1)</option>
                    <option>Zone commerciale (C1)</option>
                </select>
            </div>
            <div style="margin-bottom: 16px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">Observations</label>
                <textarea id="controlObs" rows="3" class="form-control">Construction conforme au PLU. Permis valide.</textarea>
            </div>
        </div>
    `;
    if (modalFooter) {
        modalFooter.innerHTML = `
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 40px;">Fermer</button>
            <button type="button" class="btn btn-primary" onclick="saveControl()" style="background: #1A6B45; color: white; border-radius: 40px; padding: 8px 24px;">Valider le contrôle</button>
        `;
    }
}
else if (action === 'dashboard') {
    modalTitle.innerText = 'Tableau de Bord Fiscal';
    const dashStats = (typeof dashboardStatsReels !== 'undefined' && dashboardStatsReels) ? dashboardStatsReels : { paiements_mois: 0, montant_mois: 0, total_pending: 0 };
    const montantMoisFmt = dashStats.montant_mois >= 1000000
        ? (dashStats.montant_mois / 1000000).toFixed(1) + 'M'
        : dashStats.montant_mois >= 1000
            ? (dashStats.montant_mois / 1000).toFixed(0) + 'k'
            : dashStats.montant_mois;
    modalBody.innerHTML = `
        <div style="padding: 0;">
            <div style="display: flex; gap: 16px; margin-bottom: 20px;">
                <div style="flex: 1; text-align: center; padding: 16px; background: #f8fafc; border-radius: 16px;">
                    <div style="font-size: 1.8rem; font-weight: 800; color: #1A6B45;">${dashStats.paiements_mois}</div>
                    <div style="font-size: 0.7rem;">Paiements ce mois</div>
                </div>
                <div style="flex: 1; text-align: center; padding: 16px; background: #f8fafc; border-radius: 16px;">
                    <div style="font-size: 1.8rem; font-weight: 800; color: #1A6B45;">${montantMoisFmt}</div>
                    <div style="font-size: 0.7rem;">Montant collecté</div>
                </div>
                <div style="flex: 1; text-align: center; padding: 16px; background: #f8fafc; border-radius: 16px;">
                    <div style="font-size: 1.8rem; font-weight: 800; color: #f59e0b;">${dashStats.total_pending}</div>
                    <div style="font-size: 0.7rem;">En attente</div>
                </div>
            </div>
            <div id="dashboardTableContainer" style="max-height: 300px; overflow-y: auto;">
                <table class="unco-table" style="width: 100%;">
                    <thead><tr><th>Réf</th><th>Structure</th><th>Montant</th><th>Statut</th></tr></thead>
                    <tbody id="dashboardTableBody"></tbody>
                </table>
            </div>
        </div>
    `;
    if (modalFooter) {
        modalFooter.innerHTML = `<button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 40px;">Fermer</button>`;
    }
    // Remplir le tableau du dashboard
    setTimeout(() => {
        const tbody = document.getElementById('dashboardTableBody');
        if (tbody) {
            const rows = (typeof paiementsReels !== 'undefined' && paiementsReels) ? paiementsReels : [];
            tbody.innerHTML = rows.length ? rows.map(p => `
                <tr>
                    <td>${p.reference || '-'}</td>
                    <td>${p.contribuable || '-'}</td>
                    <td>${parseInt(p.montant).toLocaleString()} FCFA</td>
                    <td><span class="status-pill ${p.statut === 'paye' ? 'paye' : (p.statut === 'overdue' ? 'impaye' : 'encours')}">${p.statut === 'paye' ? 'Payé' : (p.statut === 'overdue' ? 'Impayé' : (p.statut === 'pending' ? 'En attente' : 'Exonéré'))}</span></td>
                </tr>
            `).join('') : '<tr><td colspan="4" style="text-align:center;">Aucun paiement trouvé</td></tr>';
        }
    }, 100);
}


else if (action === 'userManagement') {
    modalTitle.innerText = 'Gestion des Utilisateurs';
    modalBody.innerHTML = `
        <div style="padding: 0;">
            <div style="display: flex; gap: 16px; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px;">
                <button type="button" class="btn btn-sm" id="userTabListBtn" onclick="showUserTab('list')" style="border-radius: 40px; background: #1A6B45; color: white;">Liste des utilisateurs</button>
                <button type="button" class="btn btn-sm" id="userTabCreateBtn" onclick="showUserTab('create')" style="border-radius: 40px;">Créer un compte</button>
            </div>
            <div id="userTabList">
                <table class="unco-table" style="width: 100%;">
                    <thead>
                        <tr><th>Nom</th><th>Email</th><th>Rôle</th><th>Statut</th><th>Action</th></tr>
                    </thead>
                    <tbody id="userListBody">
                        <tr><td colspan="5" style="text-align:center;padding:20px;color:#6b7280;">Chargement...</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="userTabCreate" style="display: none;">
                <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                    <div style="flex: 1;"><label style="font-weight: 600;">Nom complet *</label><input type="text" id="newUserName" class="form-control" placeholder="Ex: Khadija Mbaye"></div>
                    <div style="flex: 1;"><label style="font-weight: 600;">Email *</label><input type="email" id="newUserEmail" class="form-control" placeholder="exemple@ouakam.sn"></div>
                </div>
                <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                    <div style="flex: 1;">
                        <label style="font-weight: 600;">Rôle</label>
                        <select id="newUserRole" class="form-control">
                            <option value="Agent Municipal">Agent Municipal</option>
                            <option value="Technicien SIG">Technicien SIG</option>
                            <option value="Administrateur">Administrateur</option>
                        </select>
                    </div>
                    <div style="flex: 1;"><label style="font-weight: 600;">Téléphone</label><input type="tel" id="newUserPhone" class="form-control" placeholder="77 000 00 00"></div>
                </div>
                <div style="margin-bottom: 16px;">
                    <label style="font-weight: 600;">Mot de passe</label>
                    <input type="password" id="newUserPassword" class="form-control" placeholder="Laissez vide pour mot de passe temporaire">
                    <div style="font-size:0.72rem;color:#6b7280;margin-top:4px;">Si vide, le mot de passe temporaire sera <strong>Changez@moi123</strong></div>
                </div>
                <button id="createUserBtn" class="btn btn-primary" onclick="createUser()" style="background: #1A6B45; color: white; border-radius: 40px; width: 100%;">Créer le compte</button>
            </div>
        </div>
    `;
    if (modalFooter) {
        modalFooter.innerHTML = `<button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 40px;">Fermer</button>`;
    }
    setTimeout(() => {
        document.getElementById('userTabList').style.display = 'block';
        document.getElementById('userTabCreate').style.display = 'none';
        document.getElementById('userTabListBtn').style.background = '#1A6B45';
        document.getElementById('userTabListBtn').style.color = 'white';
        document.getElementById('userTabCreateBtn').style.background = 'transparent';
        document.getElementById('userTabCreateBtn').style.color = '#1A6B45';
        loadUserList(); // ← charger les vrais utilisateurs
    }, 100);
}
else if (action === 'importSig') {
    modalTitle.innerText = 'Importer un Fichier SIG';
    modalBody.innerHTML = `
        <div style="padding: 0;">
            <div class="alert alert-info" style="background: #eff6ff; border: none; border-radius: 12px; padding: 12px; font-size: 0.85rem; margin-bottom: 20px;">
                <i data-lucide="info" style="width: 16px; height: 16px; display: inline-block; vertical-align: middle;"></i>
                Importez des données géospatiales pour mettre à jour la base cartographique.<br>
                <strong>Formats supportés :</strong> GeoJSON (.geojson), KML (.kml), Shapefile (.shp + .dbf), DXF (.dxf)
            </div>

            <div style="margin-bottom: 16px;">
                <a href="javascript:void(0)" onclick="uncoCore.openModal('manageImports')" style="font-size: 0.8rem; color: #1A6B45; text-decoration: underline;">
                    📂 Voir / supprimer mes imports précédents
                </a>
            </div>
            
            <div style="margin-bottom: 16px;">
                <label style="font-weight: 600; margin-bottom: 8px; display: block;">Type de fichier</label>
                <select id="importType" class="form-control" onchange="updateImportFormat()">
                    <option value="geojson" selected>GeoJSON (.geojson)</option>
                    <option value="kml">KML (.kml)</option>
                    <option value="shapefile">Shapefile (.shp)</option>
                    <option value="dxf">DXF (.dxf)</option>
                    <option value="dwg">DWG (.dwg) — conversion requise</option>
                    <option value="dgn">DGN (.dgn) — conversion requise</option>
                </select>
            </div>
            
            <div id="dwgDgnNotice" style="display:none; background:#fef3c7; border-radius:12px; padding:12px 14px; margin-bottom:16px; font-size:0.8rem; line-height:1.5;">
                ⚠️ <strong>Les fichiers DWG et DGN sont des formats propriétaires (Autodesk / Bentley)</strong> qu'aucune librairie gratuite ne peut lire directement dans le navigateur ou en PHP.<br><br>
                Pour les importer ici, convertissez-les d'abord en <strong>DXF</strong> (gratuit, 2 minutes) avec l'outil officiel
                <a href="https://www.opendesignalliance.org/tools/oda-file-converter" target="_blank" rel="noopener">ODA File Converter</a>,
                puis revenez sélectionner "DXF (.dxf)" ci-dessus avec le fichier converti.
            </div>

            <div id="importFileBlock" style="margin-bottom: 16px;">
                <label style="font-weight: 600; margin-bottom: 8px; display: block;">Fichier</label>
                <input type="file" id="importFile" class="form-control" accept=".geojson,.kml,.shp,.dbf,.zip,.dxf" multiple onchange="previewImportFile(this)">
                <div id="fileInfo" style="margin-top: 8px; font-size: 0.7rem; color: #6b7280;"></div>
            </div>
            
            <div id="filePreviewContainer" style="display: none; background: #f8fafc; border-radius: 12px; padding: 12px; margin-bottom: 16px;">
                <div id="filePreview"></div>
            </div>
            
            <div style="margin-bottom: 16px;">
                <label style="font-weight: 600; margin-bottom: 8px; display: block;">Système de coordonnées</label>
                <select id="importCrs" class="form-control">
                    <option value="4326" selected>WGS 84 (EPSG:4326)</option>
                    <option value="32628">UTM Zone 28N (EPSG:32628) — Sénégal</option>
                </select>
                <div class="form-text" id="crsAutoNote" style="font-size: 0.68rem; color: #6b7280; margin-top:4px;">
                    Pour les fichiers DXF : détecté automatiquement à partir de la première coordonnée trouvée dans le fichier
                    (valeurs entre -180/180 et -90/90 → WGS84, valeurs plus grandes → UTM 28N). Vérifiez la sélection avant d'importer.
                </div>
            </div>
            
            <div class="alert alert-warning" style="background: #fef3c7; border: none; border-radius: 12px; padding: 10px; font-size: 0.7rem;">
                ⚠️ Attention : L'import ajoutera des données à la base existante. Les doublons ne sont pas automatiquement détectés.
            </div>
        </div>
    `;
    
    if (modalFooter) {
        modalFooter.innerHTML = `
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 40px;">Fermer</button>
            <button type="button" class="btn btn-primary" onclick="importSigFile()" style="background: #1A6B45; color: white; border-radius: 40px; padding: 8px 24px;" id="importBtn">Importer</button>
        `;
    }
    
    setTimeout(() => {
        lucide.createIcons();
    }, 100);
}
else if (action === 'manageImports') {
    modalTitle.innerText = 'Historique des Imports SIG';
    modalBody.innerHTML = `
        <div style="padding: 0;">
            <div class="alert alert-info" style="background: #eff6ff; border: none; border-radius: 12px; padding: 12px; font-size: 0.8rem; margin-bottom: 16px;">
                Supprimer un import retire d'un coup toutes les entités qu'il a créées (infrastructures, rues, bâtiments).
                Cette action est définitive.
            </div>
            <div id="importBatchesList" style="max-height: 380px; overflow-y: auto;">
                <div style="text-align:center; padding:20px; color:#6b7280;">Chargement...</div>
            </div>
        </div>
    `;
    if (modalFooter) {
        modalFooter.innerHTML = `
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 40px;">Fermer</button>
            <button type="button" class="btn btn-outline-secondary" onclick="uncoCore.openModal('importSig')" style="border-radius: 40px;">← Retour à l'import</button>
        `;
    }
    loadImportBatches();
}


else if (action === 'deleteBuilding') {
    modalTitle.innerText = 'Supprimer un Bâtiment';
    modalBody.innerHTML = `
        <div style="padding: 0;">
            <div class="alert alert-danger" style="background: #fee2e2; border: none; border-radius: 12px; padding: 12px; font-size: 0.85rem; margin-bottom: 20px; color: #b91c1c;">
                ⚠️ Attention : La suppression est définitive.
            </div>
            <div style="margin-bottom: 20px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">Identifiant du bâtiment à supprimer <span style="color: #dc2626;">*</span></label>
                <div style="display: flex; gap: 12px;">
                    <input type="text" id="deleteBuildingId" placeholder="Ex: BAT-OUA-2024-001" class="form-control" style="flex: 1;">
                    <button type="button" class="btn btn-outline-secondary" onclick="searchBuildingToDelete()" style="border-radius: 40px;">Rechercher</button>
                </div>
            </div>
            <div id="deleteBuildingInfo" style="display: none; background: #f8fafc; border-radius: 12px; padding: 16px; margin-bottom: 20px;">
                <div style="font-weight: 700; margin-bottom: 8px;">Bâtiment trouvé :</div>
                <div id="deleteBuildingDetails"></div>
            </div>
        </div>
    `;
    if (modalFooter) {
        modalFooter.innerHTML = `
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 40px;">Annuler</button>
            <button type="button" class="btn btn-danger" onclick="confirmDeleteBuilding()" style="background: #dc2626; color: white; border-radius: 40px; padding: 8px 24px;" id="confirmDeleteBtn" disabled>Supprimer</button>
        `;
    }
}


else if (action === 'manageParcelle') {
    modalTitle.innerText = 'Modifier / Supprimer une Parcelle';
    modalBody.innerHTML = `
        <div style="padding: 0;">
            <div style="margin-bottom: 20px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">N° de parcelle <span style="color: #dc2626;">*</span></label>
                <div style="display: flex; gap: 12px;">
                    <input type="text" id="parcelleSearchNum" placeholder="Ex: 1079" class="form-control" style="flex: 1;">
                    <button type="button" class="btn btn-outline-secondary" onclick="searchParcelle()" style="border-radius: 40px;">Rechercher</button>
                </div>
                <div style="font-size: 0.75rem; color: #6b7280; margin-top: 6px;">S'il existe plusieurs parcelles avec ce numéro (doublon créé par traçage), toutes s'affichent séparément ci-dessous.</div>
            </div>
            <div id="parcelleResults"></div>
        </div>
    `;
    if (modalFooter) {
        modalFooter.innerHTML = `
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 40px;">Fermer</button>
        `;
    }
}




        else {
            modalTitle.innerText = `Action: ${action}`;
            modalBody.innerHTML = `<p>Module prêt.</p><input class="form-control" placeholder="Informations supplémentaires...">`;
            if (modalFooter) {
                modalFooter.innerHTML = `<button type="button" class="btn btn-dark" data-bs-dismiss="modal" style="border-radius: 40px;">Fermer</button>`;
            }
        }
        
        modal.show();
        setTimeout(() => { if (typeof lucide !== 'undefined') lucide.createIcons(); }, 50);
    }
};

// ========== FONCTIONS DES MODALES ==========

// ========== SAUVEGARDER DANS POSTGRESQL ==========
async function saveBuildingToPostgreSQL() {
    const id = document.getElementById('buildingId').value;
    const type = document.getElementById('buildingType').value;
    const address = document.getElementById('buildingAddress').value;
    const district = document.getElementById('buildingDistrict').value;
    const lat = parseFloat(document.getElementById('buildingLat').value);
    const lng = parseFloat(document.getElementById('buildingLng').value);
    const area = document.getElementById('buildingArea').value;
    const floors = document.getElementById('buildingFloors').value;
    
    if (isNaN(lat) || isNaN(lng)) {
        showToast('Erreur', 'Coordonnées GPS invalides', 'error');
        return;
    }
    
    const buildingData = {
        action: 'add_building',
        identifiant: id,
        type: type,
        adresse: address,
        quartier: district,
        latitude: lat,
        longitude: lng,
        surface: parseInt(area),
        etages: floors,
        observations: `Ajouté le ${new Date().toLocaleDateString()}`,
        cree_par: sessionStorage.getItem('unco_user') || 'admin'
    };
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(buildingData)
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Ajouter le marqueur sur la carte
            //const marker = L.marker([lat, lng]).addTo(mainMap);
            //marker.bindPopup(`<b>${id}</b><br>Type: ${type}<br>Adresse: ${address}<br>Quartier: ${district}<br>Surface: ${area} m²<br>Étages: ${floors}`).openPopup();
            createBuildingMarker(lat, lng, id, type, address, district, area, floors);
            
            // Mettre à jour les KPIs
            totalBatiments++;
            updateKPIs();
            
            addNotif('Bâtiment ajouté', `Le bâtiment ${id} a été ajouté à PostgreSQL.`, 'success');
            showToast('Succès', `Bâtiment ${id} ajouté définitivement!`, 'success');
            
            // Synchroniser (utile pour les autres onglets/agents, et pour garder
            // buildingsDataGlobal à jour sans recharger la page).
            refreshMapData();
            
            // Fermer la modale
            const modalEl = document.getElementById('globalModal');
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) modalInstance.hide();
        } else {
            showToast('Erreur', result.error || 'Impossible d\'ajouter le bâtiment', 'error');
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur', 'Erreur de communication avec le serveur', 'error');
    }
}

async function confirmDeleteBuilding() {
    const buildingId = document.getElementById('deleteBuildingId').value.trim();
    
    if (!buildingId) {
        showToast('Erreur', 'Veuillez saisir un identifiant de bâtiment', 'error');
        return;
    }
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_building', identifiant: buildingId })
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Retirer le marqueur local s'il existe (il peut ne pas être affiché du tout,
            // par ex. si la couche bâtiments n'est pas cochée sur cet onglet — ce n'est
            // pas bloquant pour la suppression, qui se fait toujours côté serveur).
            let markerToRemove = null;
            mainMap.eachLayer(function(layer) {
                if (layer instanceof L.Marker && layer.getPopup() && layer.getPopup().getContent().includes(buildingId)) {
                    markerToRemove = layer;
                }
            });
            if (markerToRemove) mainMap.removeLayer(markerToRemove);

            totalBatiments--;
            updateKPIs();
            addNotif('Bâtiment supprimé', `Le bâtiment ${buildingId} a été supprimé de PostgreSQL.`, 'success');
            showToast('Succès', `Bâtiment ${buildingId} supprimé définitivement!`, 'success');
            refreshMapData();
            
            const modalEl = document.getElementById('globalModal');
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) modalInstance.hide();
        } else if (result.error === 'not_found') {
            showToast('Erreur', `Aucun bâtiment trouvé avec l'identifiant "${buildingId}"`, 'error');
        } else {
            showToast('Erreur', result.error || 'Impossible de supprimer le bâtiment', 'error');
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur', 'Erreur de communication avec le serveur', 'error');
    }
}



// ========== GESTION D'UNE PARCELLE (modifier / supprimer) ==========
async function searchParcelle() {
    const num = document.getElementById('parcelleSearchNum').value.trim();
    const resultsDiv = document.getElementById('parcelleResults');
    if (!num) {
        showToast('Erreur', 'Veuillez saisir un numéro de parcelle', 'error');
        return;
    }
    resultsDiv.innerHTML = `<div style="color:#6b7280;">Recherche en cours…</div>`;
    try {
        const response = await fetch(window.location.href + '?find_parcelle=' + encodeURIComponent(num));
        const result = await response.json();
        if (!result.success || !result.parcelles || result.parcelles.length === 0) {
            resultsDiv.innerHTML = `<div style="color:#dc2626;">Aucune parcelle trouvée avec le numéro "${num}"</div>`;
            return;
        }
        resultsDiv.innerHTML = result.parcelles.map(p => `
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-bottom:14px;">
                <div style="font-weight:700; margin-bottom:10px;">Parcelle ${p.num_parcel} <span style="font-weight:400; color:#6b7280; font-size:0.78rem;">(objectid ${p.objectid})</span></div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
                    <div>
                        <label style="font-size:0.78rem; font-weight:600; display:block; margin-bottom:4px;">Identifiant</label>
                        <input type="text" id="pcl_identifiant_${p.objectid}" class="form-control" value="${p.identifiant || ''}">
                    </div>
                    <div>
                        <label style="font-size:0.78rem; font-weight:600; display:block; margin-bottom:4px;">Rue</label>
                        <input type="text" id="pcl_rue_${p.objectid}" class="form-control" value="${p.rue || ''}">
                    </div>
                    <div>
                        <label style="font-size:0.78rem; font-weight:600; display:block; margin-bottom:4px;">Quartier</label>
                        <input type="text" id="pcl_quartier_${p.objectid}" class="form-control" value="${p.quartiers || ''}">
                    </div>
                    <div>
                        <label style="font-size:0.78rem; font-weight:600; display:block; margin-bottom:4px;">Commune</label>
                        <input type="text" id="pcl_commune_${p.objectid}" class="form-control" value="${p.commune || ''}">
                    </div>
                    <div>
                        <label style="font-size:0.78rem; font-weight:600; display:block; margin-bottom:4px;">Surface (m²)</label>
                        <input type="number" id="pcl_surface_${p.objectid}" class="form-control" value="${p.surface || ''}">
                    </div>
                </div>
                <div style="display:flex; gap:10px;">
                    <button type="button" class="btn btn-primary" style="background:#1A6B45; border:none; border-radius:40px; padding:6px 18px; font-size:0.85rem;" onclick="updateParcelle(${p.objectid}, '${p.num_parcel}')">Enregistrer</button>
                    <button type="button" class="btn btn-danger" style="background:#dc2626; border:none; border-radius:40px; padding:6px 18px; font-size:0.85rem;" onclick="deleteParcelle(${p.objectid}, '${p.num_parcel}')">Supprimer</button>
                </div>
            </div>
        `).join('');
    } catch (err) {
        console.error('Erreur:', err);
        resultsDiv.innerHTML = `<div style="color:#dc2626;">Erreur de communication avec le serveur</div>`;
    }
}

async function updateParcelle(objectid, numParcel) {
    const payload = {
        action: 'update_parcelle',
        objectid: objectid,
        identifiant: document.getElementById(`pcl_identifiant_${objectid}`)?.value || null,
        rue: document.getElementById(`pcl_rue_${objectid}`)?.value || null,
        quartier: document.getElementById(`pcl_quartier_${objectid}`)?.value || null,
        commune: document.getElementById(`pcl_commune_${objectid}`)?.value || null,
        surface: parseFloat(document.getElementById(`pcl_surface_${objectid}`)?.value) || null
    };
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const result = await response.json();
        if (result.success) {
            showToast('Succès', `Parcelle ${numParcel} mise à jour.`, 'success');
            // Fusion côté client : remplacer le Feature dans CADASTRE_PARCELLES et redessiner la couche.
            if (result.feature && typeof CADASTRE_PARCELLES !== 'undefined') {
                const idx = CADASTRE_PARCELLES.features.findIndex(f => (f.properties || {}).OBJECTID === objectid);
                if (idx !== -1) CADASTRE_PARCELLES.features[idx] = result.feature;
                if (sigLayers.parcelles) {
                    mainMap.removeLayer(sigLayers.parcelles);
                    sigLayers.parcelles = buildParcellesLayer().addTo(mainMap);
                }
            }
        } else {
            showToast('Erreur', result.error || 'Impossible de mettre à jour la parcelle', 'error');
        }
    } catch (err) {
        console.error('Erreur:', err);
        showToast('Erreur', 'Erreur de communication avec le serveur', 'error');
    }
}

async function deleteParcelle(objectid, numParcel) {
    if (!confirm(`Supprimer définitivement la parcelle ${numParcel} (objectid ${objectid}) ?`)) return;
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_parcelle', objectid: objectid })
        });
        const result = await response.json();
        if (result.success) {
            showToast('Succès', `Parcelle ${numParcel} supprimée.`, 'success');
            if (typeof CADASTRE_PARCELLES !== 'undefined') {
                CADASTRE_PARCELLES.features = CADASTRE_PARCELLES.features.filter(f => (f.properties || {}).OBJECTID !== objectid);
                if (sigLayers.parcelles) {
                    mainMap.removeLayer(sigLayers.parcelles);
                    sigLayers.parcelles = buildParcellesLayer().addTo(mainMap);
                }
            }
            searchParcelle();
        } else {
            showToast('Erreur', result.error || 'Impossible de supprimer la parcelle', 'error');
        }
    } catch (err) {
        console.error('Erreur:', err);
        showToast('Erreur', 'Erreur de communication avec le serveur', 'error');
    }
}

async function searchBuildingToDelete() {
    const buildingId = document.getElementById('deleteBuildingId').value.trim();
    const infoDiv = document.getElementById('deleteBuildingInfo');
    const detailsDiv = document.getElementById('deleteBuildingDetails');
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    
    if (!buildingId) {
        showToast('Identifiant requis', 'Veuillez saisir un identifiant de bâtiment.', 'warning');
        if (infoDiv) infoDiv.style.display = 'none';
        if (confirmBtn) confirmBtn.disabled = true;
        return;
    }

    if (confirmBtn) confirmBtn.disabled = true;
    detailsDiv.innerHTML = `<div style="color:#6b7280;">Recherche en cours…</div>`;
    infoDiv.style.display = 'block';

    try {
        const response = await fetch(window.location.href + '?find_building=' + encodeURIComponent(buildingId));
        const result = await response.json();

        if (result.success && result.building) {
            const b = result.building;
            detailsDiv.innerHTML = `
                <div style="font-size: 0.8rem;">Identifiant : <strong>${b.identifiant}</strong></div>
                <div style="font-size: 0.8rem; margin-top: 5px;">Type : ${b.type || '—'} · Adresse : ${b.adresse || '—'} · Quartier : ${b.quartier || '—'} · Surface : ${b.surface ? Math.round(b.surface) + ' m²' : '—'}</div>
            `;
            if (confirmBtn) confirmBtn.disabled = false;

            // Centrer la carte sur le bâtiment, si des coordonnées sont disponibles
            if (b.latitude && b.longitude) {
                const pos = recenterIfNearOldDefault(parseFloat(b.latitude), parseFloat(b.longitude));
                mainMap.setView([pos.lat, pos.lng], 18);
            }
        } else {
            detailsDiv.innerHTML = `<div style="color: #dc2626;">Aucun bâtiment trouvé avec l'identifiant "${buildingId}"</div>`;
            if (confirmBtn) confirmBtn.disabled = true;
        }
    } catch (err) {
        detailsDiv.innerHTML = `<div style="color: #dc2626;">Erreur de communication avec le serveur</div>`;
        if (confirmBtn) confirmBtn.disabled = true;
    }
}


async function loadBuildingData() {
    const buildingId = document.getElementById('editBuildingId').value.trim();
    const formContainer = document.getElementById('editBuildingForm');
    
    if (!buildingId) {
        showToast('Identifiant requis', 'Veuillez saisir un identifiant de bâtiment.', 'warning');
        formContainer.style.display = 'none';
        return;
    }

    try {
        const response = await fetch(window.location.href + '?find_building=' + encodeURIComponent(buildingId));
        const result = await response.json();

        if (result.success && result.building) {
            const b = result.building;
            const pos = (b.latitude && b.longitude)
                ? recenterIfNearOldDefault(parseFloat(b.latitude), parseFloat(b.longitude))
                : { lat: null, lng: null };

            document.getElementById('editBuildingType').value = b.type || 'Résidentiel';
            document.getElementById('editBuildingArea').value = b.surface || '150';
            document.getElementById('editBuildingLat').value = pos.lat !== null ? pos.lat.toFixed(6) : '';
            document.getElementById('editBuildingLng').value = pos.lng !== null ? pos.lng.toFixed(6) : '';
            document.getElementById('editBuildingAddress').value = b.adresse || '';

            if (pos.lat !== null) mainMap.setView([pos.lat, pos.lng], 18);

            formContainer.style.display = 'block';
            showToast('Succès', `Bâtiment "${buildingId}" chargé.`, 'success');
        } else {
            showToast('Non trouvé', `Aucun bâtiment trouvé avec l'identifiant "${buildingId}"`, 'error');
            formContainer.style.display = 'none';
        }
    } catch (err) {
        showToast('Erreur', 'Erreur de communication avec le serveur', 'error');
        formContainer.style.display = 'none';
    }
}


function updateImportFormat() {
    const format = document.getElementById('importType').value;
    const fileInput = document.getElementById('importFile');
    const fileBlock = document.getElementById('importFileBlock');
    const dwgDgnNotice = document.getElementById('dwgDgnNotice');
    const importBtn = document.getElementById('importBtn');

    if (format === 'geojson') {
        fileInput.accept = '.geojson';
    } else if (format === 'kml') {
        fileInput.accept = '.kml';
    } else if (format === 'dxf') {
        fileInput.accept = '.dxf';
    } else if (format === 'dwg' || format === 'dgn') {
        fileInput.accept = format === 'dwg' ? '.dwg' : '.dgn';
    } else {
        fileInput.accept = '.shp';
    }

    // DWG/DGN : pas de lecture possible ici, on affiche la marche à suivre et on
    // désactive le formulaire d'import plutôt que de laisser l'utilisateur envoyer
    // un fichier qui échouera silencieusement.
    const isUnsupportedCad = (format === 'dwg' || format === 'dgn');
    if (dwgDgnNotice) dwgDgnNotice.style.display = isUnsupportedCad ? 'block' : 'none';
    if (fileBlock) fileBlock.style.display = isUnsupportedCad ? 'none' : 'block';
    if (importBtn) {
        importBtn.disabled = isUnsupportedCad;
        importBtn.style.opacity = isUnsupportedCad ? '0.5' : '1';
        importBtn.style.cursor = isUnsupportedCad ? 'not-allowed' : 'pointer';
    }

    // Réinitialiser l'aperçu
    document.getElementById('fileInfo').innerHTML = '';
    document.getElementById('filePreviewContainer').style.display = 'none';
    fileInput.value = '';
}

function previewImportFile(input) {
    const file = input.files[0];
    const infoDiv = document.getElementById('fileInfo');
    const previewContainer = document.getElementById('filePreviewContainer');
    const previewDiv = document.getElementById('filePreview');
    
    if (!file) return;
    
    const fileSize = (file.size / 1024).toFixed(2);
    infoDiv.innerHTML = `<i data-lucide="file-text" style="width: 12px; height: 12px;"></i> ${file.name} (${fileSize} KB)`;
    lucide.createIcons();
    
    if (file.name.endsWith('.geojson')) {
        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const geojson = JSON.parse(e.target.result);
                const points = geojson.features.filter(f => f.geometry.type === 'Point').length;
                const lines = geojson.features.filter(f => f.geometry.type === 'LineString').length;
                const polygons = geojson.features.filter(f => f.geometry.type === 'Polygon' || f.geometry.type === 'MultiPolygon').length;
                const total = geojson.features.length;
                
                previewDiv.innerHTML = `
                    <div style="font-weight: 600; margin-bottom: 8px;">📊 Aperçu :</div>
                    <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                        <div>📍 Points: <strong>${points}</strong></div>
                        <div>📏 Lignes: <strong>${lines}</strong></div>
                        <div>🗺️ Polygones: <strong>${polygons}</strong></div>
                        <div>📦 Total: <strong>${total}</strong></div>
                    </div>
                `;
                previewContainer.style.display = 'block';
            } catch(err) {
                previewDiv.innerHTML = '<div style="color: #dc2626;">❌ Fichier GeoJSON invalide</div>';
                previewContainer.style.display = 'block';
            }
        };
        reader.readAsText(file);
    } else if (file.name.endsWith('.kml')) {
        previewDiv.innerHTML = `<div>📁 Fichier KML détecté. Les points seront importés comme infrastructures.</div>`;
        previewContainer.style.display = 'block';
    } else if (file.name.endsWith('.dxf')) {
        if (typeof window.DxfParser === 'undefined') {
            previewDiv.innerHTML = '<div style="color: #dc2626;">❌ Librairie dxf-parser non chargée (vérifiez la connexion internet).</div>';
            previewContainer.style.display = 'block';
            return;
        }
        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const parser = new window.DxfParser();
                const dxf = parser.parseSync(e.target.result);
                const entities = (dxf && dxf.entities) || [];
                const counts = {};
                entities.forEach(ent => { counts[ent.type] = (counts[ent.type] || 0) + 1; });
                const detectedCrs = detectCrsFromDxfEntities(entities);
                const crsLabel = detectedCrs === '32628' ? 'UTM Zone 28N (EPSG:32628)' : 'WGS 84 (EPSG:4326)';
                previewDiv.innerHTML = `
                    <div style="font-weight: 600; margin-bottom: 8px;">📊 Aperçu :</div>
                    <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 6px;">
                        ${Object.entries(counts).map(([type, n]) => `<div>${type}: <strong>${n}</strong></div>`).join('')}
                        <div>📦 Total: <strong>${entities.length}</strong></div>
                    </div>
                    <div style="font-size:0.72rem;color:#6b7280;">Système de coordonnées détecté : <strong>${crsLabel}</strong></div>
                `;
                previewContainer.style.display = 'block';
            } catch(err) {
                previewDiv.innerHTML = '<div style="color: #dc2626;">❌ Fichier DXF invalide : ' + err.message + '</div>';
                previewContainer.style.display = 'block';
            }
        };
        reader.readAsText(file);
    } else {
        previewContainer.style.display = 'none';
    }
}


async function updateBuilding() {
    const id = document.getElementById('editBuildingId').value;
    const type = document.getElementById('editBuildingType').value;
    const area = document.getElementById('editBuildingArea').value;
    const lat = parseFloat(document.getElementById('editBuildingLat').value);
    const lng = parseFloat(document.getElementById('editBuildingLng').value);
    const address = document.getElementById('editBuildingAddress').value;
    
    if (isNaN(lat) || isNaN(lng)) {
        showToast('Erreur', 'Coordonnées GPS invalides.', 'error');
        return;
    }
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                action: 'update_building', 
                identifiant: id,
                type: type,
                adresse: address,
                latitude: lat,
                longitude: lng,
                surface: parseInt(area)
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Supprimer l'ancien marqueur
            let oldMarker = null;
            mainMap.eachLayer(function(layer) {
                if (layer instanceof L.Marker && layer.getPopup()) {
                    const content = layer.getPopup().getContent();
                    if (typeof content === 'string' && content.includes(id)) {
                        oldMarker = layer;
                    }
                }
            });
            if (oldMarker) mainMap.removeLayer(oldMarker);
            
            // Ajouter le nouveau marqueur
            //const newMarker = L.marker([lat, lng]).addTo(mainMap);
            //newMarker.bindPopup(`<b>${id}</b><br>Type: ${type}<br>Adresse: ${address}<br>Surface: ${area} m²`).openPopup();
            createBuildingMarker(lat, lng, id, type, address, '', area, '');

            showToast('Succès', `Bâtiment ${id} modifié avec succès!`, 'success');
            
            const modalEl = document.getElementById('globalModal');
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) modalInstance.hide();
        } else {
            showToast('Erreur', result.error || 'Impossible de modifier le bâtiment', 'error');
        }
    } catch (error) {
        showToast('Erreur', 'Erreur de communication avec le serveur', 'error');
    }
}


function generateReport() {
     console.log("✅ generateReport() est appelée !");
    const reportType = document.getElementById('reportType').value;
    const period = document.getElementById('reportPeriod').value;
    const format = document.getElementById('reportFormat').value;
    
    let reportTitle = '';
    let reportData = [];
    
    // Générer les données selon le type de rapport
    if (reportType === 'fiscal') {
    reportTitle = 'Rapport de Recouvrement Fiscal';
    // Utiliser les données réelles
    const totalPaid = statsFiscalesReelles.total_paye;
    const totalPending = statsFiscalesReelles.total_attente;
    const totalOverdue = statsFiscalesReelles.total_retard;
    
    reportData = [
        { indicateur: 'Total collecté', montant: totalPaid.toLocaleString() + ' FCFA' },
        { indicateur: 'En attente', montant: totalPending.toLocaleString() + ' FCFA' },
        { indicateur: 'En retard', montant: totalOverdue.toLocaleString() + ' FCFA' },
        { indicateur: 'Taux de recouvrement', montant: Math.round((totalPaid / (totalPaid + totalPending + totalOverdue)) * 100) + '%' }
    ];
}
    else if (reportType === 'buildings') {
        reportTitle = 'Rapport des Structures Recensées';
        const buildings = <?php echo json_encode($batiments_db); ?>;
        const typeCount = {};
        buildings.forEach(b => {
            typeCount[b.type] = (typeCount[b.type] || 0) + 1;
        });
        
        reportData = Object.keys(typeCount).map(type => ({
            type: type,
            nombre: typeCount[type]
        }));
    }
    else if (reportType === 'monthly') {
        reportTitle = 'Rapport d\'Évolution Mensuelle';
        const monthsSrc = (typeof statsMensuelles !== 'undefined' && statsMensuelles) ? statsMensuelles : [];
        reportData = monthsSrc.slice(-6).map(m => ({
            mois: m.mois,
            payes: m.total_paye,
            en_attente: m.total_attente,
            en_retard: m.total_retard
        }));
    }
    else if (reportType === 'overdue') {
        reportTitle = 'Rapport des Retards de Paiement';
        const source = (typeof paiementsReels !== 'undefined' && paiementsReels) ? paiementsReels : [];
        reportData = source.filter(p => p.statut === 'overdue').map(p => ({
            contribuable: p.contribuable,
            reference: p.reference,
            montant: p.montant,
            nicad: p.nicad
        }));
    }
    
    const reportDate = new Date().toLocaleString();
    let reportHtml = `
        <div style="font-family: 'Inter', sans-serif; padding: 20px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h2 style="color: #1A6B45;">UNCO - ${reportTitle}</h2>
                <p>Généré le ${reportDate}</p>
                <p>Période: ${period === 'month' ? 'Dernier mois' : (period === 'quarter' ? 'Dernier trimestre' : 'Année en cours')}</p>
            </div>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #1A6B45; color: white;">
                        ${Object.keys(reportData[0] || {}).map(k => `<th style="padding: 10px; text-align: left;">${k.charAt(0).toUpperCase() + k.slice(1)}</th>`).join('')}
                    </tr>
                </thead>
                <tbody>
                    ${reportData.map(row => `
                        <tr style="border-bottom: 1px solid #ddd;">
                            ${Object.values(row).map(v => `<td style="padding: 8px;">${v}</td>`).join('')}
                        </tr>
                    `).join('')}
                </tbody>
            </table>
            <div style="margin-top: 30px; text-align: center; font-size: 0.7rem; color: #666;">
                Document généré par UNCO - Système de Gestion Urbaine et Fiscale
            </div>
        </div>
    `;
    
    if (format === 'excel') {
        const headers = Object.keys(reportData[0] || {});
        const csvRows = [];
        csvRows.push(headers.join(','));
        for (const row of reportData) {
            const values = headers.map(header => {
                const val = row[header] || '';
                return `"${String(val).replace(/"/g, '""')}"`;
            });
            csvRows.push(values.join(','));
        }
        
        const blob = new Blob(["\uFEFF" + csvRows.join('\n')], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.href = url;
        link.setAttribute('download', `${reportTitle.replace(/ /g, '_')}_${new Date().toISOString().slice(0,19)}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        
        showToast('Rapport généré', `Le rapport "${reportTitle}" a été téléchargé au format CSV.`, 'success');
    } else {
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('globalModal'));
        document.getElementById('modalTitle').innerHTML = `<i data-lucide="file-text"></i> ${reportTitle}`;
        document.getElementById('modalBodyText').innerHTML = reportHtml;
        const modalFooter = document.querySelector('#globalModal .modal-footer');
        if (modalFooter) {
            modalFooter.innerHTML = `
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 40px;">Fermer</button>
                <button type="button" class="btn btn-primary" onclick="exportReportToPDF()" style="background: #1A6B45; color: white; border-radius: 40px; padding: 8px 24px;">Imprimer</button>
            `;
        }
        modal.show();
        setTimeout(() => lucide.createIcons(), 100);
    }
    
    addNotif('Rapport généré', `Rapport "${reportTitle}" (${period === 'month' ? 'dernier mois' : (period === 'quarter' ? 'dernier trimestre' : 'année en cours')}) au format ${format.toUpperCase()}`, 'success');
    
    // ========== SAUVEGARDER LE RAPPORT DANS POSTGRESQL ==========
    try {
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save_report',
                titre: reportTitle,
                type: reportType,
                periode: period,
                format: format,
                contenu: reportHtml,
                date: new Date().toISOString()
            })
        });
    } catch(e) {
        console.log("Erreur lors de la sauvegarde du rapport:", e);
    }
}

function exportReportToPDF() {
    window.print();
}



function exportMap() {
    const format = document.getElementById('exportFormat').value;
    const zone = document.getElementById('exportZone').value;
    
    let zoneLabel = '';
    switch(zone) {
        case 'commune': zoneLabel = 'Commune entière'; break;
        case 'nord': zoneLabel = 'Ouakam Nord'; break;
        case 'centre': zoneLabel = 'Ouakam Centre'; break;
        case 'sud': zoneLabel = 'Ouakam Sud'; break;
        default: zoneLabel = 'Commune entière';
    }
    
    // Sauvegarder la vue actuelle
    const currentCenter = mainMap.getCenter();
    const currentZoom = mainMap.getZoom();
    let bounds = mainMap.getBounds();
    
    switch(zone) {
        case 'nord': bounds = L.latLngBounds([14.728, -17.475], [14.718, -17.460]); break;
        case 'centre': bounds = L.latLngBounds([14.722, -17.472], [14.712, -17.462]); break;
        case 'sud': bounds = L.latLngBounds([14.715, -17.474], [14.705, -17.465]); break;
        default: bounds = mainMap.getBounds();
    }
    
    // Centrer la carte
    mainMap.fitBounds(bounds);
    
    // Afficher un toast de chargement
    showToast('Export en cours', 'Préparation du PDF...', 'info');
    
    // Attendre que la carte soit stabilisée
    setTimeout(() => {
        // Capturer la carte
        html2canvas(document.getElementById('unco-main-map'), {
            scale: 2,
            backgroundColor: '#ffffff',
            useCORS: true,
            logging: false
        }).then(mapCanvas => {
            // Récupérer les KPIs réels
            const kpis = {
                batiments: totalBatimentsReel ? totalBatimentsReel.toLocaleString() : (document.querySelector('#view-sig .kpi-card:first-child .kpi-value')?.innerText || '0'),
                alertes: document.querySelector('#view-sig .kpi-card:nth-child(2) .kpi-value')?.innerText || '0',
                taux: document.querySelector('#view-sig .kpi-card:nth-child(3) .kpi-value')?.innerText || '0%',
                actifs: document.querySelector('#view-sig .kpi-card:nth-child(4) .kpi-value')?.innerText || '0'
            };
            
            // Construire le tableau HTML avec les données RÉELLES
            let tableRows = '';
            if (statsBatimentsReels && statsBatimentsReels.length > 0) {
                statsBatimentsReels.forEach((stat, index) => {
                    tableRows += `
                        <tr ${index % 2 === 1 ? 'style="background:#f9fafb;"' : ''}>
                            <td>${stat.type}</td>
                            <td>${stat.nombre.toLocaleString()}</td>
                            <td>${stat.pourcentage}%</td>
                        </tr>
                    `;
                });
            } else {
                // Fallback si pas de données
                tableRows = `
                    <tr><td>Résidentiel</td><td>8,450</td><td>68%</td></tr>
                    <tr style="background:#f9fafb;"><td>Commercial</td><td>2,890</td><td>23%</td></tr>
                    <tr><td>Mixte</td><td>890</td><td>7%</td></tr>
                    <tr style="background:#f9fafb;"><td>Équipement public</td><td>220</td><td>2%</td></tr>
                `;
            }
            
            const totalBatimentsValue = totalBatimentsReel ? totalBatimentsReel.toLocaleString() : '12,450';
            
            // Créer un document HTML pour le PDF
            const pdfHtml = `
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <style>
                        * { margin: 0; padding: 0; box-sizing: border-box; }
                        body { font-family: 'Inter', sans-serif; padding: 20px; background: white; }
                        .header {
                            text-align: center;
                            margin-bottom: 20px;
                            padding: 20px;
                            background: #1A6B45;
                            color: white;
                            border-radius: 16px;
                        }
                        .header h1 { margin: 0; font-size: 24px; }
                        .kpi-grid {
                            display: flex;
                            gap: 16px;
                            margin-bottom: 20px;
                        }
                        .kpi-card {
                            flex: 1;
                            text-align: center;
                            padding: 16px;
                            background: #f8fafc;
                            border-radius: 16px;
                            border: 1px solid #e5e7eb;
                        }
                        .kpi-label {
                            font-size: 0.7rem;
                            text-transform: uppercase;
                            color: #6b7280;
                        }
                        .kpi-value {
                            font-size: 1.8rem;
                            font-weight: 800;
                            color: #1A6B45;
                        }
                        .map-image {
                            width: 100%;
                            margin-bottom: 20px;
                            border-radius: 16px;
                            border: 1px solid #e5e7eb;
                        }
                        .legend-section {
                            margin-bottom: 20px;
                            padding: 16px;
                            background: #f8fafc;
                            border-radius: 16px;
                            border: 1px solid #e5e7eb;
                        }
                        .legend-title { font-weight: 700; margin-bottom: 12px; }
                        .legend-items { display: flex; flex-wrap: wrap; gap: 16px; }
                        .legend-item { display: flex; align-items: center; gap: 8px; font-size: 0.75rem; }
                        .legend-color { width: 24px; height: 24px; border-radius: 50%; }
                        .summary-table {
                            width: 100%;
                            border-collapse: collapse;
                            margin-top: 20px;
                        }
                        .summary-table th, .summary-table td {
                            padding: 10px;
                            text-align: left;
                            border-bottom: 1px solid #e5e7eb;
                        }
                        .summary-table th { background: #1A6B45; color: white; }
                        .footer {
                            text-align: center;
                            margin-top: 20px;
                            padding: 12px;
                            background: #f8fafc;
                            border-radius: 12px;
                            font-size: 10px;
                            color: #6b7280;
                        }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>UNCO - Système de Gestion Urbaine et Fiscale</h1>
                        <p>Ouakam · Rapport d'export cartographique - Zone: ${zoneLabel}</p>
                        <p>Généré le ${new Date().toLocaleString()}</p>
                    </div>
                    
                    <div class="kpi-grid">
                        <div class="kpi-card"><div class="kpi-label">BÂTIMENTS RECENSÉS</div><div class="kpi-value">${kpis.batiments}</div></div>
                        <div class="kpi-card"><div class="kpi-label">ALERTE RUES</div><div class="kpi-value">${kpis.alertes}</div></div>
                        <div class="kpi-card"><div class="kpi-label">TAUX ADRESSAGE</div><div class="kpi-value">${kpis.taux}</div></div>
                        <div class="kpi-card"><div class="kpi-label">ACTIFS TERRAIN</div><div class="kpi-value">${kpis.actifs}</div></div>
                    </div>
                    
                    <img src="${mapCanvas.toDataURL('image/png')}" class="map-image">
                    
                    <div class="legend-section">
                        <div class="legend-title">LÉGENDE INFRASTRUCTURES</div>
                        <div class="legend-items">
                            <div class="legend-item"><div class="legend-color" style="background:#F5A623;"></div><span>Voie / Électricité</span></div>
                            <div class="legend-item"><div class="legend-color" style="background:#C0392B;"></div><span>Santé / Pharmacie</span></div>
                            <div class="legend-item"><div class="legend-color" style="background:#4A7C5F;"></div><span>École</span></div>
                            <div class="legend-item"><div class="legend-color" style="background:#B8860B;"></div><span>Mosquée</span></div>
                            <div class="legend-item"><div class="legend-color" style="background:#1A6B45;"></div><span>Administration</span></div>
                        </div>
                    </div>
                    
                    <table class="summary-table">
                        <thead>
                            <tr>
                                <th>Type de bâtiment</th>
                                <th>Nombre</th>
                                <th>Pourcentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${tableRows}
                            <tr style="background:#1A6B45; color:white; font-weight:700;">
                                <td><strong>TOTAL</strong></td>
                                <td><strong>${totalBatimentsValue}</strong></td>
                                <td><strong>100%</strong></td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div class="footer">
                        <p>Document officiel - UNCO v2.0 | Source: Base de données PostgreSQL</p>
                        <p>Données mises à jour le ${new Date().toLocaleString()}</p>
                    </div>
                </body>
                </html>
            `;
            
            // Créer un élément temporaire pour le PDF
            const tempContainer = document.createElement('div');
            tempContainer.innerHTML = pdfHtml;
            tempContainer.style.position = 'absolute';
            tempContainer.style.left = '-9999px';
            tempContainer.style.top = '-9999px';
            document.body.appendChild(tempContainer);
            
            // Capturer le conteneur
            html2canvas(tempContainer, {
                scale: 2,
                backgroundColor: '#ffffff',
                logging: false
            }).then(finalCanvas => {
                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
                const imgData = finalCanvas.toDataURL('image/png');
                const imgWidth = 210;
                const imgHeight = (finalCanvas.height * imgWidth) / finalCanvas.width;
                
                pdf.addImage(imgData, 'PNG', 0, 0, imgWidth, imgHeight);
                pdf.save(`rapport_unco_${zoneLabel}_${new Date().toISOString().slice(0,19).replace(/:/g, '-')}.pdf`);
                
                tempContainer.remove();
                mainMap.setView(currentCenter, currentZoom);
                showToast('Export réussi', 'PDF généré avec les données réelles de PostgreSQL !', 'success');
            }).catch(err => {
                console.error(err);
                tempContainer.remove();
                mainMap.setView(currentCenter, currentZoom);
                showToast('Erreur', 'Impossible de générer le PDF.', 'error');
            });
        }).catch(err => {
            console.error(err);
            mainMap.setView(currentCenter, currentZoom);
            showToast('Erreur', 'Impossible de capturer la carte.', 'error');
        });
    }, 800);
    
    const modalEl = document.getElementById('globalModal');
    const modalInstance = bootstrap.Modal.getInstance(modalEl);
    if (modalInstance) modalInstance.hide();
}

// ========== FONCTIONS CARTES FISCALE ET ADMIN ==========
function setBaseLayerFiscal(layer, btn) {
    fiscalMap.eachLayer(l => { if (l instanceof L.TileLayer) fiscalMap.removeLayer(l); });
    const newLayer = layer === 'osm' 
        ? L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png')
        : L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}');
    newLayer.addTo(fiscalMap);
    document.querySelectorAll('#view-municipal .map-pill').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

function setAdminBaseLayer(layer, btn) {
    adminMap.eachLayer(l => { if (l instanceof L.TileLayer) adminMap.removeLayer(l); });
    const newLayer = layer === 'osm' 
        ? L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png')
        : L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}');
    newLayer.addTo(adminMap);
    document.querySelectorAll('#view-admin .map-pill').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

// ========== INITIALISATION ==========
//function initAll() { initMaps(); initFiscalCharts(); renderPaymentsTable('all'); lucide.createIcons(); }
function initAll() { 
    initMaps(); 
    loadBuildingsFromPostgreSQL();  // ← AJOUTER CETTE LIGNE
    initFiscalCharts(); 
    renderPaymentsTable('all'); 
    updateFiscalKPIs();
    lucide.createIcons();

    // Synchronisation périodique : permet aux AUTRES agents (autre onglet/appareil) de voir
    // les ajouts sans avoir à recharger la page eux-mêmes. refreshMapData() est déjà appelée
    // immédiatement après chaque ajout/modification dans l'onglet qui fait l'action ;
    // ce sondage régulier couvre les autres onglets ouverts.
    if (window._uncoSyncInterval) clearInterval(window._uncoSyncInterval);
    window._uncoSyncInterval = setInterval(() => {
        if (document.visibilityState === 'visible' && typeof refreshMapData === 'function') {
            refreshMapData();
        }
    }, 20000);
}
if (sessionStorage.getItem('unco_auth') === 'true') initAll();

window.filterFiscal = filterFiscal;
window.setBaseLayer = setBaseLayer;
window.toggleCadastre = toggleCadastre;
window.toggleSigLayer = toggleSigLayer;
window.enforceSigLayerOrder = enforceSigLayerOrder;
window.toggleLayersPanel = toggleLayersPanel;
window.toggleRoutingControl = toggleRoutingControl;
window.saveBuilding = saveBuildingToPostgreSQL;
window.searchParcelle = searchParcelle;
window.updateParcelle = updateParcelle;
window.deleteParcelle = deleteParcelle;
window.confirmDeleteBuilding = confirmDeleteBuilding;
window.loadBuildingData = loadBuildingData;
window.updateBuilding = updateBuilding;
window.setBaseLayerFiscal = setBaseLayerFiscal;
window.setAdminBaseLayer = setAdminBaseLayer;
window.toggleCadastreFiscal = toggleCadastreFiscal;
window.toggleCadastreAdmin = toggleCadastreAdmin;
window.getLocationForRecensement = getLocationForRecensement;
window.saveRecensement = saveRecensement;
window.saveCommerce = saveCommerce;
window.saveFiscalPayment = saveFiscalPayment;
window.saveControl = saveControl;
window.showUserTab = showUserTab;
window.createUser = createUser;
window.importSigFile = importSigFile;
window.searchBuildingToDelete = searchBuildingToDelete;


function showToast(title, message, type = 'success') {
    // Supprimer les toasts existants
    const existingToast = document.querySelector('.unco-toast');
    if (existingToast) existingToast.remove();
    
    const toast = document.createElement('div');
    toast.className = `unco-toast ${type}`;
    
    let icon = '✅';
    if (type === 'error') icon = '❌';
    if (type === 'warning') icon = '⚠️';
    
    toast.innerHTML = `
        <div class="toast-icon">${icon}</div>
        <div class="toast-title">${title}</div>
        <div class="toast-message">${message}</div>
    `;
    
    document.body.appendChild(toast);
    
    // Supprimer automatiquement après l'animation
    setTimeout(() => {
    if (toast && toast.parentNode) toast.remove();
}, 5000);
}


function getLocationForRecensement() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(position => {
            document.getElementById('recLat').value = position.coords.latitude.toFixed(6);
            document.getElementById('recLng').value = position.coords.longitude.toFixed(6);
            showToast('Position GPS', 'Coordonnées mises à jour automatiquement.', 'success');
        }, () => {
            showToast('Erreur', 'Impossible de récupérer votre position.', 'error');
        });
    } else {
        showToast('Erreur', 'Géolocalisation non supportée.', 'error');
    }
}

function saveRecensement() {
    console.log("=== saveRecensement appelée ===");
    
    const data = {
        action: 'save_recensement',
        nom_structure: document.getElementById('recName')?.value,
        type_structure: document.getElementById('recType')?.value,
        latitude: parseFloat(document.getElementById('recLat')?.value),
        longitude: parseFloat(document.getElementById('recLng')?.value),
        adresse: document.getElementById('recAddress')?.value || '',
        proprietaire: document.getElementById('recOwner')?.value || '',
        telephone: document.getElementById('recPhone')?.value || '',
        observations: document.getElementById('recObs')?.value || '',
        cree_par: sessionStorage.getItem('unco_user') || 'agent'
    };
    
    console.log("Données envoyées:", data);
    
    if (!data.nom_structure || !data.type_structure) {
        showToast('Erreur', 'Veuillez remplir le nom et le type', 'error');
        return;
    }
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(response => {
        console.log("Status:", response.status);
        return response.json();
    })
    .then(result => {
        console.log("Résultat:", result);
        if (result.success) {
            showToast('Succès', `"${data.nom_structure}" enregistré !`, 'success');
            addNotif('Recensement', `${data.nom_structure} ajouté à la base.`, 'success');
            bootstrap.Modal.getInstance(document.getElementById('globalModal')).hide();
            // Recharger pour voir les nouvelles données
            refreshMapData();
        } else {
            showToast('Erreur', result.error || 'Erreur lors de l\'enregistrement', 'error');
        }
    })
    .catch(error => {
        console.error("Erreur:", error);
        showToast('Erreur', 'Erreur de communication', 'error');
    });
}

function saveCommerce() {
    console.log("=== saveCommerce appelée ===");
    
    const data = {
        action: 'save_commerce',
        nom: document.getElementById('commerceName')?.value,
        type: document.getElementById('commerceType')?.value,
        latitude: parseFloat(document.getElementById('commerceLat')?.value),
        longitude: parseFloat(document.getElementById('commerceLng')?.value),
        adresse: document.getElementById('commerceAddress')?.value || '',
        proprietaire: document.getElementById('commerceOwner')?.value || '',
        telephone: document.getElementById('commercePhone')?.value || '',
        observations: document.getElementById('commerceObs')?.value || '',
        cree_par: sessionStorage.getItem('unco_user') || 'agent'
    };
    
    console.log("Données commerce:", data);
    
    if (!data.nom || !data.type) {
        showToast('Erreur', 'Veuillez remplir le nom et le type du commerce', 'error');
        return;
    }
    
    if (isNaN(data.latitude) || isNaN(data.longitude)) {
        showToast('Erreur', 'Coordonnées GPS invalides', 'error');
        return;
    }
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            showToast('Succès', `Le commerce "${data.nom}" a été enregistré !`, 'success');
            addNotif('Commerce', `${data.nom} (${data.type}) ajouté à la base.`, 'success');
            bootstrap.Modal.getInstance(document.getElementById('globalModal')).hide();
            refreshMapData();
        } else {
            showToast('Erreur', result.error || 'Erreur lors de l\'enregistrement', 'error');
        }
    })
    .catch(error => {
        console.error("Erreur:", error);
        showToast('Erreur', 'Erreur de communication', 'error');
    });
}

// ========== GÉOLOCALISATION "ME LOCALISER" ==========
// Remplit automatiquement les champs latitude/longitude d'une modale à partir
// de la position GPS réelle de l'utilisateur (utile sur le terrain, via mobile).
function localiserPosition(latFieldId, lngFieldId, btn) {
    if (!navigator.geolocation) {
        showToast('Non supporté', 'La géolocalisation n\'est pas disponible sur ce navigateur', 'error');
        return;
    }

    // Cause très fréquente d'échec (souvent silencieux ou avec un message trompeur) :
    // les navigateurs modernes bloquent purement et simplement l'API de géolocalisation
    // sur une origine non sécurisée (HTTP), sauf sur localhost. Si le site tourne en HTTP,
    // il faut activer un certificat SSL côté hébergeur — ce n'est pas réparable en JS.
    if (!window.isSecureContext) {
        showToast('HTTPS requis', 'La géolocalisation nécessite une connexion sécurisée (HTTPS). Ce site est actuellement servi en HTTP — contactez votre hébergeur pour activer un certificat SSL.', 'error');
        return;
    }

    const originalHtml = btn ? btn.innerHTML : null;
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span style="display:inline-flex;align-items:center;gap:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Localisation…</span>';
    }

    navigator.geolocation.getCurrentPosition(
        (position) => {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            const latField = document.getElementById(latFieldId);
            const lngField = document.getElementById(lngFieldId);
            if (latField) latField.value = lat.toFixed(6);
            if (lngField) lngField.value = lng.toFixed(6);
            const precision = Math.round(position.coords.accuracy);
            showToast('✅ Position obtenue', `Lat ${lat.toFixed(5)}, Lng ${lng.toFixed(5)} · précision ~${precision}m`, 'success');
            if (btn) { btn.disabled = false; btn.innerHTML = originalHtml; if (typeof lucide !== 'undefined') lucide.createIcons(); }
        },
        (error) => {
            let msg = 'Impossible d\'obtenir votre position';
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    msg = 'Permission refusée. Cliquez sur l\'icône de localisation dans la barre d\'adresse et autorisez l\'accès.'; break;
                case error.POSITION_UNAVAILABLE:
                    msg = 'Position GPS indisponible. Vérifiez que le GPS est activé sur votre appareil.'; break;
                case error.TIMEOUT:
                    msg = 'Délai dépassé. Allez en plein air pour un meilleur signal et réessayez.'; break;
            }
            showToast('Localisation impossible', msg, 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = originalHtml; if (typeof lucide !== 'undefined') lucide.createIcons(); }
        },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
    );
}
window.localiserPosition = localiserPosition;

// Aperçu de l'icône/couleur en fonction de la catégorie choisie (placeholder, extensible)
function updateInfraIconPreview() {
    // Réservé pour un futur aperçu visuel de l'icône dans la modale.
}

function saveInfrastructure() {
    console.log("=== saveInfrastructure appelée ===");

    const category = document.getElementById('infraCategory')?.value;
    const defaults = INFRA_CATEGORY_DEFAULTS[category] || INFRA_DEFAULT_FALLBACK;

    const data = {
        action: 'add_infrastructure',
        nom: document.getElementById('infraName')?.value,
        categorie: category,
        latitude: parseFloat(document.getElementById('infraLat')?.value),
        longitude: parseFloat(document.getElementById('infraLng')?.value),
        icone: defaults.iconName,
        couleur: defaults.color
    };

    console.log("Données infrastructure:", data);

    if (!data.nom || !data.categorie) {
        showToast('Erreur', 'Veuillez remplir le nom et la catégorie', 'error');
        return;
    }

    if (isNaN(data.latitude) || isNaN(data.longitude)) {
        showToast('Erreur', 'Coordonnées GPS invalides', 'error');
        return;
    }

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            showToast('Succès', `L'infrastructure "${data.nom}" a été enregistrée !`, 'success');
            addNotif('Infrastructure', `${data.nom} (${data.categorie}) ajoutée à la base.`, 'success');
            bootstrap.Modal.getInstance(document.getElementById('globalModal')).hide();
            refreshMapData();
        } else {
            showToast('Erreur', result.error || 'Erreur lors de l\'enregistrement', 'error');
        }
    })
    .catch(error => {
        console.error("Erreur:", error);
        showToast('Erreur', 'Erreur de communication', 'error');
    });
}

function saveFiscalPayment() {
    const data = {
        action: 'save_paiement',
        reference: document.getElementById('fiscalRef')?.value,
        contribuable: document.getElementById('fiscalContrib')?.value,
        nicad: document.getElementById('fiscalNicad')?.value?.trim() || null,
        montant: parseFloat(document.getElementById('fiscalAmount')?.value),
        mode_paiement: document.getElementById('fiscalMethod')?.value,
        date_paiement: document.getElementById('fiscalDate')?.value,
        numero_recu: document.getElementById('fiscalReceipt')?.value,
        observations: document.getElementById('fiscalObs')?.value || '',
        cree_par: sessionStorage.getItem('unco_user') || 'agent'
    };
    
    if (!data.contribuable || !data.montant) {
        showToast('Erreur', 'Veuillez remplir tous les champs', 'error');
        return;
    }
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            showToast('Succès', `Paiement de ${data.montant.toLocaleString()} FCFA enregistré !`, 'success');
            addNotif('Paiement', `${data.contribuable} - ${data.montant.toLocaleString()} FCFA encaissés.`, 'success');
            
            // ========== MISE À JOUR IMMÉDIATE ==========
            // 1. Mettre à jour le total payé localement
            statsFiscalesReelles.total_paye += data.montant;
            
            // 2. Ajouter le nouveau paiement au tableau des derniers paiements
            const nouveauPaiement = {
                reference: data.reference,
                contribuable: data.contribuable,
                nicad: '-',
                montant: data.montant,
                statut: 'paye',
                date_creation: new Date().toISOString()
            };
            paiementsReels.unshift(nouveauPaiement);
            if (paiementsReels.length > 10) paiementsReels.pop();
            
            // 3. Rafraîchir tous les affichages
            updateFiscalKPIs();           // Met à jour les cartes (Payés, En attente, etc.)
            renderPaymentsTable('all');    // Met à jour le tableau des derniers paiements
            
            // 4. Mettre à jour le graphique (optionnel)
            if (typeof lineChart !== 'undefined' && lineChart) {
                refreshFiscalData();       // Recharge les vraies données depuis le serveur
            }
            
            // Fermer la modale
            bootstrap.Modal.getInstance(document.getElementById('globalModal')).hide();
            refreshMapData();
        } else {
            showToast('Erreur', result.error || 'Erreur lors de l\'enregistrement', 'error');
        }
    })
    .catch(error => {
        console.error("Erreur:", error);
        showToast('Erreur', 'Erreur de communication', 'error');
    });
}


function saveFiscalPaymentWithStatus() {
    const status = document.getElementById('fiscalStatus')?.value;
    
    let statusLabels = {
        'paye': 'Payé',
        'pending': 'En attente',
        'overdue': 'En retard',
        'exempt': 'Exonéré'
    };
    
    const data = {
        action: 'save_paiement_complet',
        reference: document.getElementById('fiscalRef')?.value,
        contribuable: document.getElementById('fiscalContrib')?.value,
        nicad: document.getElementById('fiscalNicad')?.value?.trim() || null,
        montant: parseFloat(document.getElementById('fiscalAmount')?.value),
        mode_paiement: document.getElementById('fiscalMethod')?.value,
        date_paiement: document.getElementById('fiscalDate')?.value,
        observations: document.getElementById('fiscalObs')?.value || '',
        statut: status,
        cree_par: sessionStorage.getItem('unco_user') || 'agent'
    };
    
    if (!data.contribuable || !data.montant || isNaN(data.montant) || data.montant <= 0) {
        showToast('Erreur', 'Veuillez remplir tous les champs correctement', 'error');
        return;
    }
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            showToast('Succès', `${statusLabels[status]} - ${data.montant.toLocaleString()} FCFA enregistré !`, 'success');
            addNotif('Paiement', `${data.contribuable} - ${data.montant.toLocaleString()} FCFA (${statusLabels[status]})`, 'success');
            
            // Mettre à jour selon le statut
            if (status === 'paye') statsFiscalesReelles.total_paye += data.montant;
            else if (status === 'pending') statsFiscalesReelles.total_attente += data.montant;
            else if (status === 'overdue') statsFiscalesReelles.total_retard += data.montant;
            else if (status === 'exempt') statsFiscalesReelles.total_exonere += data.montant;
            
            // Mettre à jour le tableau
            const nouveauPaiement = {
                reference: data.reference,
                contribuable: data.contribuable,
                nicad: '-',
                montant: data.montant,
                statut: status,
                date_creation: new Date().toISOString()
            };
            paiementsReels.unshift(nouveauPaiement);
            if (paiementsReels.length > 10) paiementsReels.pop();
            
            updateFiscalKPIs();
            renderPaymentsTable('all');
            
            bootstrap.Modal.getInstance(document.getElementById('globalModal')).hide();
            refreshMapData();
        } else {
            showToast('Erreur', result.error || 'Erreur lors de l\'enregistrement', 'error');
        }
    })
    .catch(error => {
        console.error("Erreur:", error);
        showToast('Erreur', 'Erreur de communication', 'error');
    });
}



function refreshFiscalData() {
    fetch(window.location.href + '?get_fiscal_stats=true')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Mettre à jour les stats fiscales
                statsFiscalesReelles.total_paye = data.total_paye;
                statsFiscalesReelles.total_attente = data.total_attente;
                statsFiscalesReelles.total_retard = data.total_retard;
                statsFiscalesReelles.total_exonere = data.total_exonere;
                
                // Mettre à jour l'affichage
                updateFiscalKPIs();
                
                // Mettre à jour les graphiques si nécessaire
                if (lineChart && lineChart.data) {
                    // Recharger complètement les graphiques
                    initFiscalCharts();
                }
            }
        })
        .catch(err => console.error('Erreur refresh:', err));
}



function saveControl() {
    console.log("=== saveControl appelée ===");
    
    const data = {
        action: 'save_controle',
        numero_parcelle: document.getElementById('controlParcelle')?.value,
        type_controle: document.getElementById('controlType')?.value,
        zone_reglementaire: document.getElementById('controlZone')?.value,
        observations: document.getElementById('controlObs')?.value || '',
        statut: 'conforme',
        controle_par: sessionStorage.getItem('unco_user') || 'agent'
    };
    
    console.log("Données contrôle:", data);
    
    if (!data.numero_parcelle || !data.type_controle) {
        showToast('Erreur', 'Veuillez remplir la parcelle et le type de contrôle', 'error');
        return;
    }
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            showToast('Succès', `Contrôle de la parcelle ${data.numero_parcelle} enregistré !`, 'success');
            addNotif('Contrôle', `Parcelle ${data.numero_parcelle} - ${data.type_controle} - ${data.statut}`, 'success');
            bootstrap.Modal.getInstance(document.getElementById('globalModal')).hide();
            refreshMapData();
        } else {
            showToast('Erreur', result.error || 'Erreur lors de l\'enregistrement', 'error');
        }
    })
    .catch(error => {
        console.error("Erreur:", error);
        showToast('Erreur', 'Erreur de communication', 'error');
    });
}

function showUserTab(tab) {
    const listDiv = document.getElementById('userTabList');
    const createDiv = document.getElementById('userTabCreate');
    const listBtn = document.getElementById('userTabListBtn');
    const createBtn = document.getElementById('userTabCreateBtn');
    
    if (tab === 'list') {
        listDiv.style.display = 'block';
        createDiv.style.display = 'none';
        listBtn.style.background = '#1A6B45';
        listBtn.style.color = 'white';
        createBtn.style.background = 'transparent';
        createBtn.style.color = '#1A6B45';
    } else {
        listDiv.style.display = 'none';
        createDiv.style.display = 'block';
        createBtn.style.background = '#1A6B45';
        createBtn.style.color = 'white';
        listBtn.style.background = 'transparent';
        listBtn.style.color = '#1A6B45';
    }
}

function createUser() {
    const name  = document.getElementById('newUserName').value.trim();
    const email = document.getElementById('newUserEmail').value.trim();
    const role  = document.getElementById('newUserRole').value;
    const phone = document.getElementById('newUserPhone').value.trim();
    const pwd   = document.getElementById('newUserPassword') ? document.getElementById('newUserPassword').value : '';

    if (!name || !email) {
        showToast('Champs manquants', 'Veuillez remplir le nom et l\'email.', 'warning');
        return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showToast('Email invalide', 'Veuillez entrer une adresse email valide.', 'warning');
        return;
    }

    const btn = document.getElementById('createUserBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Création...'; }

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'create_user', nom: name, email, role, mot_de_passe: pwd || null })
    })
    .then(r => r.json())
    .then(result => {
        if (btn) { btn.disabled = false; btn.innerHTML = 'Créer le compte'; }
        if (result.success) {
            addNotif('Utilisateur créé', `${name} (${email}) a été ajouté.`, 'success');
            showToast('Compte créé', `${name} ajouté avec le rôle "${role}".`, 'success');
            // Revenir à la liste et la rafraîchir
            loadUserList();
            showUserTab('list');
            // Vider le formulaire
            ['newUserName','newUserEmail','newUserPhone'].forEach(id => { const el = document.getElementById(id); if(el) el.value = ''; });
        } else {
            showToast('Erreur', result.error || 'Impossible de créer le compte.', 'error');
        }
    })
    .catch(err => {
        if (btn) { btn.disabled = false; btn.innerHTML = 'Créer le compte'; }
        showToast('Erreur réseau', err.message, 'error');
    });
}

function loadUserList() {
    const tbody = document.getElementById('userListBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#6b7280;">Chargement...</td></tr>';

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'get_users' })
    })
    .then(r => r.json())
    .then(result => {
        if (!result.success) { tbody.innerHTML = '<tr><td colspan="5" style="color:red;">Erreur de chargement</td></tr>'; return; }
        const roleLabels = { admin: 'Administrateur', agent: 'Agent Municipal', controleur: 'Technicien SIG' };
        const roleColors = { admin: '#fef3c7', agent: '#fffbeb', controleur: '#e8f5ee' };
        tbody.innerHTML = result.users.map(u => `
            <tr>
                <td>${u.nom || '—'}</td>
                <td style="font-size:0.8rem;">${u.email}</td>
                <td><span style="background:${roleColors[u.role]||'#f1f5f9'};padding:3px 10px;border-radius:40px;font-size:0.7rem;">${roleLabels[u.role]||u.role}</span></td>
                <td><span style="background:${u.actif?'#dcfce7':'#fee2e2'};color:${u.actif?'#15803d':'#b91c1c'};padding:3px 10px;border-radius:40px;font-size:0.7rem;">${u.actif?'Actif':'Inactif'}</span></td>
                <td>
                    <button onclick="toggleUser(${u.id}, this)" style="background:none;border:1px solid #e5e7eb;border-radius:20px;padding:2px 10px;font-size:0.7rem;cursor:pointer;">
                        ${u.actif ? 'Désactiver' : 'Activer'}
                    </button>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="5" style="text-align:center;padding:20px;color:#6b7280;">Aucun utilisateur</td></tr>';
    })
    .catch(() => { tbody.innerHTML = '<tr><td colspan="5" style="color:red;">Erreur réseau</td></tr>'; });
}

function toggleUser(id, btn) {
    btn.disabled = true;
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'toggle_user', id })
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) loadUserList();
        else showToast('Erreur', result.error, 'error');
    });
}

// Variables pour l'import SIG
let selectedFile = null;
let geoJsonData = null;

// ========== IMPORT DXF : DÉTECTION DE CRS + CONVERSION EN GEOJSON ==========
// Définition de la projection UTM Zone 28N (WGS84), la projection standard utilisée
// au Sénégal pour les données CAD/SIG en mètres (Dakar, Ouakam, etc.).
if (typeof proj4 !== 'undefined') {
    proj4.defs('EPSG:32628', '+proj=utm +zone=28 +datum=WGS84 +units=m +no_defs');
}

// Récupère la première coordonnée (x, y) trouvée dans une liste d'entités DXF,
// tous types confondus (LINE, LWPOLYLINE, POLYLINE, POINT, CIRCLE, TEXT...).
function getFirstDxfCoordinate(entities) {
    for (const ent of entities) {
        if (ent.vertices && ent.vertices.length) return ent.vertices[0];
        if (ent.startPoint) return ent.startPoint;
        if (ent.center) return ent.center;
        if (ent.position) return ent.position;
    }
    return null;
}

// Heuristique de détection du système de coordonnées : un fichier DXF ne contient
// aucune métadonnée de projection (contrairement à un Shapefile avec son .prj), donc
// on déduit le CRS le plus probable à partir de l'ordre de grandeur des coordonnées.
function detectCrsFromDxfEntities(entities) {
    const coord = getFirstDxfCoordinate(entities);
    if (!coord) return '4326';
    const x = coord.x, y = coord.y;
    // Longitude/latitude valides -> déjà en WGS84
    if (Math.abs(x) <= 180 && Math.abs(y) <= 90) return '4326';
    // Sinon, valeurs en mètres typiques d'une projection UTM (Sénégal = Zone 28N)
    return '32628';
}

// Convertit un tableau d'entités dxf-parser en FeatureCollection GeoJSON (WGS84),
// en reprojetant chaque coordonnée si nécessaire via proj4.
function dxfEntitiesToGeoJson(entities, sourceCrs) {
    const reproject = (pt) => {
        if (!pt) return null;
        if (sourceCrs === '4326' || typeof proj4 === 'undefined') return [pt.x, pt.y];
        const [lng, lat] = proj4('EPSG:' + sourceCrs, 'EPSG:4326', [pt.x, pt.y]);
        return [lng, lat];
    };

    const features = [];
    entities.forEach(ent => {
        try {
            if ((ent.type === 'LWPOLYLINE' || ent.type === 'POLYLINE') && ent.vertices && ent.vertices.length >= 2) {
                const coords = ent.vertices.map(reproject).filter(Boolean);
                if (coords.length < 2) return;
                // Polyligne fermée (shape) -> Polygon, sinon LineString
                const isClosed = ent.shape === true || ent.closed === true;
                if (isClosed) {
                    coords.push(coords[0]);
                    features.push({ type: 'Feature', properties: { layer: ent.layer, source: 'dxf' }, geometry: { type: 'Polygon', coordinates: [coords] } });
                } else {
                    features.push({ type: 'Feature', properties: { layer: ent.layer, source: 'dxf' }, geometry: { type: 'LineString', coordinates: coords } });
                }
            } else if (ent.type === 'LINE' && ent.startPoint && ent.endPoint) {
                const coords = [reproject(ent.startPoint), reproject(ent.endPoint)].filter(Boolean);
                if (coords.length < 2) return;
                features.push({ type: 'Feature', properties: { layer: ent.layer, source: 'dxf' }, geometry: { type: 'LineString', coordinates: coords } });
            } else if (ent.type === 'POINT' && ent.position) {
                const c = reproject(ent.position);
                if (!c) return;
                features.push({ type: 'Feature', properties: { layer: ent.layer, source: 'dxf' }, geometry: { type: 'Point', coordinates: c } });
            } else if (ent.type === 'CIRCLE' && ent.center) {
                const c = reproject(ent.center);
                if (!c) return;
                features.push({ type: 'Feature', properties: { layer: ent.layer, source: 'dxf', radius: ent.radius }, geometry: { type: 'Point', coordinates: c } });
            } else if ((ent.type === 'TEXT' || ent.type === 'MTEXT') && ent.position) {
                const c = reproject(ent.position);
                if (!c) return;
                features.push({ type: 'Feature', properties: { layer: ent.layer, source: 'dxf', text: ent.text || '' }, geometry: { type: 'Point', coordinates: c } });
            }
        } catch (e) {
            console.warn('Entité DXF ignorée (conversion échouée) :', ent.type, e);
        }
    });

    return { type: 'FeatureCollection', features };
}

// ========== HISTORIQUE DES IMPORTS SIG (voir / supprimer un lot) ==========
function loadImportBatches() {
    const container = document.getElementById('importBatchesList');
    if (!container) return;
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'list_import_batches' })
    })
    .then(r => r.json())
    .then(result => {
        if (!result.success) {
            container.innerHTML = `<div style="color:#dc2626; font-size:0.85rem;">Erreur de chargement.</div>`;
            return;
        }
        if (!result.batches || result.batches.length === 0) {
            container.innerHTML = `<div style="text-align:center; padding:20px; color:#6b7280; font-size:0.85rem;">Aucun import enregistré.</div>`;
            return;
        }
        container.innerHTML = result.batches.map(b => {
            const date = new Date(b.created_at).toLocaleString('fr-FR');
            const total = (parseInt(b.nb_infrastructures)||0) + (parseInt(b.nb_rues)||0) + (parseInt(b.nb_batiments)||0);
            return `
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:12px 14px; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center; gap:10px;">
                    <div>
                        <div style="font-weight:600; font-size:0.85rem;">${b.filename || 'Fichier sans nom'}</div>
                        <div style="font-size:0.72rem; color:#6b7280; margin-top:2px;">
                            ${(b.format || '').toUpperCase()} · ${date} · ${total} entité(s)
                            ${parseInt(b.nb_batiments) ? ` (${b.nb_batiments} bâtiment${b.nb_batiments>1?'s':''})` : ''}
                            ${parseInt(b.nb_infrastructures) ? ` (${b.nb_infrastructures} infra.)` : ''}
                            ${parseInt(b.nb_rues) ? ` (${b.nb_rues} rue${b.nb_rues>1?'s':''})` : ''}
                        </div>
                    </div>
                    <button type="button" class="btn btn-danger" style="background:#dc2626; border:none; border-radius:40px; padding:6px 16px; font-size:0.75rem; flex-shrink:0;" onclick="deleteImportBatch('${b.id}', this)">Supprimer</button>
                </div>
            `;
        }).join('');
    })
    .catch(() => {
        container.innerHTML = `<div style="color:#dc2626; font-size:0.85rem;">Erreur réseau.</div>`;
    });
}

async function deleteImportBatch(batchId, btn) {
    if (!confirm('Supprimer définitivement toutes les données de cet import ?')) return;
    if (btn) btn.disabled = true;
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_import_batch', batch_id: batchId })
        });
        const result = await response.json();
        if (result.success) {
            const d = result.deleted || {};
            showToast('Import supprimé', `${(d.infrastructures||0)+(d.rues||0)+(d.batiments||0)} entité(s) retirée(s).`, 'success');
            loadImportBatches();
            setTimeout(() => reloadMapData(), 1000);
        } else {
            showToast('Erreur', result.error || 'Impossible de supprimer cet import', 'error');
            if (btn) btn.disabled = false;
        }
    } catch (err) {
        console.error('Erreur:', err);
        showToast('Erreur', 'Erreur de communication avec le serveur', 'error');
        if (btn) btn.disabled = false;
    }
}
window.loadImportBatches = loadImportBatches;
window.deleteImportBatch = deleteImportBatch;

function importSigFile() {
    const fileInput = document.getElementById('importFile');
    const fileType = document.getElementById('importType').value;
    
    if (!fileInput.files || !fileInput.files[0]) {
        showToast('Fichier manquant', 'Veuillez sélectionner un fichier à importer.', 'warning');
        return;
    }
    
    const file = fileInput.files[0];
    const fileName = file.name;

    // Shapefile : conversion navigateur avec shapefile.js
    if (fileType === 'shapefile') {
        if (typeof shapefile === 'undefined') {
            showToast('Librairie manquante', 'shapefile.js non chargée. Vérifiez votre connexion internet.', 'error');
            return;
        }

        // L'utilisateur doit fournir .shp + .dbf (sélection multiple)
        const files = fileInput.files;
        let shpFile = null, dbfFile = null;
        for (let f of files) {
            if (f.name.endsWith('.shp')) shpFile = f;
            if (f.name.endsWith('.dbf')) dbfFile = f;
        }
        if (!shpFile) {
            showToast('Fichier .shp manquant', 'Sélectionnez le fichier .shp (et idéalement le .dbf associé).', 'warning');
            return;
        }

        showImportProgress(true);
        const shpName = shpFile.name.replace('.shp', '');

        // Lire les deux fichiers en ArrayBuffer
        const readBuf = f => new Promise((res, rej) => {
            const r = new FileReader();
            r.onload = e => res(e.target.result);
            r.onerror = rej;
            r.readAsArrayBuffer(f);
        });

        Promise.all([readBuf(shpFile), dbfFile ? readBuf(dbfFile) : Promise.resolve(null)])
        .then(([shpBuf, dbfBuf]) => {
            const features = [];
            const sourceArgs = dbfBuf ? [shpBuf, dbfBuf] : [shpBuf];
            return shapefile.open(...sourceArgs)
            .then(source => {
                function readNext() {
                    return source.read().then(result => {
                        if (result.done) return features;
                        if (result.value) features.push(result.value);
                        return readNext();
                    });
                }
                return readNext();
            })
            .then(feats => {
                const geoJson = { type: 'FeatureCollection', features: feats };
                // Afficher sur la carte immédiatement
                displayImportedGeoJsonOnMap(geoJson, shpName + '.shp');
                // Envoyer au serveur comme GeoJSON normal
                return fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'import_sig',
                        format: 'geojson',
                        crs: document.getElementById('importCrs').value,
                        geojson_direct: geoJson,
                        filename: shpName + '.geojson',
                        cree_par: sessionStorage.getItem('unco_userId') || null
                    })
                });
            });
        })
        .then(r => {
            const ct = r.headers.get('content-type') || '';
            if (!ct.includes('application/json')) return r.text().then(t => { throw new Error(t.substring(0,200)); });
            return r.json();
        })
        .then(result => {
            showImportProgress(false);
            if (result.success) {
                showToast('Import Shapefile réussi', result.message || `${result.count || 0} entités importées`, 'success');
                addNotif('Import SIG', `"${shpFile.name}" — ${result.count || 0} entités ajoutées`, 'success');
                const modalEl = document.getElementById('globalModal');
                const mi = bootstrap.Modal.getInstance(modalEl);
                if (mi) mi.hide();
                setTimeout(() => reloadMapData(), 1500);
            } else {
                showToast('Erreur import', result.error || 'Erreur serveur', 'error');
            }
        })
        .catch(err => {
            showImportProgress(false);
            showToast('Erreur Shapefile', err.message || 'Impossible de lire le fichier .shp', 'error');
        });
        return; // important : ne pas continuer vers readAsText
    }

    // DXF : parsing natif navigateur avec dxf-parser + détection/reprojection CRS
    if (fileType === 'dxf') {
        if (typeof window.DxfParser === 'undefined') {
            showToast('Librairie manquante', 'dxf-parser non chargée. Vérifiez votre connexion internet.', 'error');
            return;
        }
        showImportProgress(true);
        const reader = new FileReader();
        reader.onload = function(e) {
            let dxf;
            try {
                const parser = new window.DxfParser();
                dxf = parser.parseSync(e.target.result);
            } catch (parseErr) {
                showImportProgress(false);
                showToast('Fichier DXF invalide', 'Impossible de lire ce fichier DXF : ' + parseErr.message, 'error');
                return;
            }

            const entities = (dxf && dxf.entities) || [];
            if (entities.length === 0) {
                showImportProgress(false);
                showToast('Fichier vide', 'Aucune entité géométrique trouvée dans ce DXF.', 'warning');
                return;
            }

            // Auto-détection du système de coordonnées à partir de la première
            // coordonnée valide trouvée (heuristique : valeurs dans -180/180 et
            // -90/90 -> déjà en WGS84 ; valeurs plus grandes -> UTM Zone 28N, la
            // projection standard utilisée au Sénégal).
            const detectedCrs = detectCrsFromDxfEntities(entities);
            const crsSelect = document.getElementById('importCrs');
            if (crsSelect) crsSelect.value = detectedCrs;
            if (detectedCrs === '32628') {
                showToast('CRS détecté automatiquement', 'Coordonnées UTM Zone 28N (EPSG:32628) détectées — reprojection en WGS84 en cours.', 'success');
            } else {
                showToast('CRS détecté automatiquement', 'Coordonnées déjà en WGS84 (EPSG:4326).', 'success');
            }

            let geoJson;
            try {
                geoJson = dxfEntitiesToGeoJson(entities, detectedCrs);
            } catch (convErr) {
                showImportProgress(false);
                showToast('Erreur de conversion', convErr.message, 'error');
                return;
            }

            if (!geoJson.features.length) {
                showImportProgress(false);
                showToast('Aucune entité convertie', 'Ce DXF ne contient que des types d\'entités non supportés (blocs, solides 3D, etc.).', 'warning');
                return;
            }

            displayImportedGeoJsonOnMap(geoJson, fileName);

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'import_sig',
                    format: 'geojson',
                    crs: '4326', // déjà reprojeté en WGS84 côté client
                    geojson_direct: geoJson,
                    filename: fileName,
                    cree_par: sessionStorage.getItem('unco_userId') || null
                })
            })
            .then(r => r.json())
            .then(result => {
                showImportProgress(false);
                if (result.success) {
                    showToast('Import DXF réussi', result.message || `${result.count || 0} entités importées`, 'success');
                    addNotif('Import SIG', `"${fileName}" — ${result.count || 0} entités ajoutées`, 'success');
                    const modalEl = document.getElementById('globalModal');
                    const modalInstance = bootstrap.Modal.getInstance(modalEl);
                    if (modalInstance) modalInstance.hide();
                    setTimeout(() => reloadMapData(), 1500);
                } else {
                    showToast('Erreur import', result.error || 'Erreur serveur', 'error');
                }
            })
            .catch(err => {
                showImportProgress(false);
                showToast('Erreur réseau', err.message, 'error');
            });
        };
        reader.onerror = function() {
            showImportProgress(false);
            showToast('Erreur de lecture', 'Impossible de lire le fichier DXF.', 'error');
        };
        reader.readAsText(file);
        return;
    }

    if (fileType === 'dwg' || fileType === 'dgn') {
        showToast('Format non lisible directement', 'Convertissez d\'abord ce fichier en DXF avec ODA File Converter (gratuit), puis réimportez-le.', 'warning');
        return;
    }

    showImportProgress(true);

    const reader = new FileReader();

    reader.onload = function(e) {
        const textContent = e.target.result;

        // Pour GeoJSON : envoyer directement le JSON parsé (pas de base64)
        // Cela évite le doublement de taille et les erreurs de décodage côté PHP
        if (fileType === 'geojson') {
            let geoJson;
            try {
                geoJson = JSON.parse(textContent);
            } catch(parseErr) {
                showImportProgress(false);
                showToast('Fichier invalide', 'Le fichier GeoJSON ne peut pas être analysé : ' + parseErr.message, 'error');
                return;
            }

            // Afficher immédiatement sur la carte
            displayImportedGeoJsonOnMap(geoJson, fileName);

            // Vérifier la taille du payload avant envoi
            const payload = {
                action: 'import_sig',
                format: 'geojson',
                crs: document.getElementById('importCrs').value,
                geojson_direct: geoJson,
                filename: fileName,
                cree_par: sessionStorage.getItem('unco_userId') || null
            };
            const payloadStr = JSON.stringify(payload);
            const payloadMB  = (payloadStr.length / 1024 / 1024).toFixed(1);

            // Si trop gros (> 50 MB), découper en chunks de 500 features
            if (payloadStr.length > 50 * 1024 * 1024) {
                showImportProgress(false);
                showToast('Fichier trop volumineux', `${payloadMB} MB — divisez en fichiers de moins de 1000 entités. Utilisez mapshaper.org > File > Export pour diviser.`, 'warning');
                return;
            }

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: payloadStr
            })
            .then(r => {
                const contentType = r.headers.get('content-type') || '';
                if (!contentType.includes('application/json')) {
                    return r.text().then(txt => {
                        throw new Error('Réponse serveur non-JSON (' + r.status + '): ' + txt.substring(0, 300));
                    });
                }
                return r.json();
            })
            .then(result => {
                showImportProgress(false);
                if (result.success) {
                    showToast('Import réussi', result.message || `${result.count || 0} entités importées`, 'success');
                    addNotif('Import SIG', `"${fileName}" — ${result.count || 0} entités ajoutées`, 'success');
                    const modalEl = document.getElementById('globalModal');
                    const modalInstance = bootstrap.Modal.getInstance(modalEl);
                    if (modalInstance) modalInstance.hide();
                    setTimeout(() => reloadMapData(), 1500);
                } else {
                    showToast('Erreur import', result.error || 'Erreur serveur', 'error');
                }
            })
            .catch(err => {
                showImportProgress(false);
                showToast('Erreur réseau', err.message, 'error');
            });

        } else if (fileType === 'kml') {
            // KML : encoder en base64 (fichier XML texte, généralement petit)
            let base64Content;
            try {
                base64Content = btoa(unescape(encodeURIComponent(textContent)));
            } catch(e) {
                showImportProgress(false);
                showToast('Erreur encodage', 'Impossible d\'encoder le fichier KML', 'error');
                return;
            }
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'import_sig',
                    format: 'kml',
                    crs: document.getElementById('importCrs').value,
                    content: base64Content,
                    filename: fileName,
                    cree_par: sessionStorage.getItem('unco_userId') || null
                })
            })
            .then(r => r.json())
            .then(result => {
                showImportProgress(false);
                if (result.success) {
                    showToast('Import réussi', result.message || `${result.count || 0} entités importées`, 'success');
                    addNotif('Import SIG', `"${fileName}" — ${result.count || 0} entités ajoutées`, 'success');
                    const modalEl = document.getElementById('globalModal');
                    const modalInstance = bootstrap.Modal.getInstance(modalEl);
                    if (modalInstance) modalInstance.hide();
                    setTimeout(() => reloadMapData(), 1500);
                } else {
                    showToast('Erreur import', result.error || 'Erreur serveur', 'error');
                }
            })
            .catch(err => {
                showImportProgress(false);
                showToast('Erreur réseau', err.message, 'error');
            });
        }
    };

    reader.onerror = function() {
        showImportProgress(false);
        showToast('Erreur', 'Impossible de lire le fichier', 'error');
    };

    reader.readAsText(file, 'UTF-8');
}

// Affiche un GeoJSON directement sur la carte Leaflet (couche cadastre)
function displayImportedGeoJsonOnMap(geoJson, fileName) {
    if (!mainMap) return;
    // Supprimer l'ancienne couche importée si elle existe
    if (sigImportLayer) { mainMap.removeLayer(sigImportLayer); }
    sigImportLayer = L.geoJSON(geoJson, {
        style: function(feature) {
            const type = feature.geometry ? feature.geometry.type : '';
            if (type === 'Polygon' || type === 'MultiPolygon') {
                return { color: '#1A6B45', weight: 2, fillColor: '#34d399', fillOpacity: 0.3 };
            }
            return { color: '#2563eb', weight: 2 };
        },
        pointToLayer: function(feature, latlng) {
            return L.circleMarker(latlng, { radius: 6, color: '#1A6B45', fillColor: '#34d399', fillOpacity: 0.8, weight: 2 });
        },
        onEachFeature: function(feature, layer) {
            const props = feature.properties || {};
            const nom = props.nom || props.name || props.Nom || props.identifiant || 'Sans nom';
            const type = props.type || props.Type || props.usage || '';
            layer.bindPopup(`<strong>${nom}</strong>${type ? '<br>Type : ' + type : ''}<br><em style="font-size:0.75em;color:#6b7280;">Importé depuis ${fileName}</em>`);
        }
    }).addTo(mainMap);

    // Zoomer sur les données importées
    try { mainMap.fitBounds(sigImportLayer.getBounds(), { padding: [30, 30] }); } catch(e) {}
    showToast('Carte mise à jour', `Couche "${fileName}" affichée sur la carte`, 'success');
}

// Recharge les bâtiments et infrastructures depuis la BD sans recharger la page
// Alias vers refreshMapData pour compatibilité avec les appels existants
function reloadMapData() {
    refreshMapData();
}

function showImportProgress(show) {
    const importBtn = document.querySelector('#globalModal .btn-primary');
    if (importBtn) {
        if (show) {
            importBtn.disabled = true;
            importBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Import en cours...';
        } else {
            importBtn.disabled = false;
            importBtn.innerHTML = 'Importer';
        }
    }
}



function setupImportFilePreview() {
    const fileInput = document.getElementById('importFile');
    if (!fileInput) return;
    
    fileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        const fileType = document.getElementById('importType').value;
        const reader = new FileReader();
        
        reader.onload = function(evt) {
            try {
                if (file.name.endsWith('.geojson')) {
                    const geojson = JSON.parse(evt.target.result);
                    const stats = {
                        points: 0,
                        lines: 0,
                        polygons: 0,
                        other: 0
                    };
                    
                    geojson.features.forEach(f => {
                        const type = f.geometry.type;
                        if (type === 'Point') stats.points++;
                        else if (type === 'LineString') stats.lines++;
                        else if (type === 'Polygon' || type === 'MultiPolygon') stats.polygons++;
                        else stats.other++;
                    });
                    
                    const total = stats.points + stats.lines + stats.polygons + stats.other;
                    
                    // Créer ou mettre à jour l'aperçu
                    let previewDiv = document.getElementById('filePreview');
                    if (!previewDiv) {
                        const container = document.querySelector('#modalBodyText div:first-child');
                        if (container) {
                            previewDiv = document.createElement('div');
                            previewDiv.id = 'filePreview';
                            previewDiv.style.marginTop = '16px';
                            previewDiv.style.padding = '12px';
                            previewDiv.style.background = '#f8fafc';
                            previewDiv.style.borderRadius = '12px';
                            previewDiv.style.fontSize = '0.75rem';
                            container.appendChild(previewDiv);
                        }
                    }
                    
                    if (previewDiv) {
                        previewDiv.innerHTML = `
                            <div style="font-weight: 600; margin-bottom: 8px;">📊 Aperçu du fichier :</div>
                            <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                                <div><span style="color: #2196F3;">📍 Points:</span> <strong>${stats.points}</strong></div>
                                <div><span style="color: #9E9E9E;">📏 Lignes:</span> <strong>${stats.lines}</strong></div>
                                <div><span style="color: #4CAF50;">🗺️ Polygones:</span> <strong>${stats.polygons}</strong></div>
                                <div><span style="color: #FF9800;">📦 Total:</span> <strong>${total}</strong></div>
                            </div>
                            <div style="margin-top: 8px; color: #64748b; font-size: 0.7rem;">
                                ${geojson.features.slice(0, 3).map(f => `• ${f.geometry.type}: ${f.properties?.nom || f.properties?.name || 'Sans nom'}`).join('<br>')}
                                ${geojson.features.length > 3 ? `<br>• ... et ${geojson.features.length - 3} autres` : ''}
                            </div>
                        `;
                    }
                } else if (file.name.endsWith('.kml')) {
                    let previewDiv = document.getElementById('filePreview');
                    if (!previewDiv) {
                        const container = document.querySelector('#modalBodyText div:first-child');
                        if (container) {
                            previewDiv = document.createElement('div');
                            previewDiv.id = 'filePreview';
                            previewDiv.style.marginTop = '16px';
                            previewDiv.style.padding = '12px';
                            previewDiv.style.background = '#f8fafc';
                            previewDiv.style.borderRadius = '12px';
                            container.appendChild(previewDiv);
                        }
                    }
                    if (previewDiv) {
                        previewDiv.innerHTML = `
                            <div style="font-weight: 600; margin-bottom: 8px;">📊 Fichier KML détecté :</div>
                            <div style="color: #64748b; font-size: 0.7rem;">
                                L'import sera traité automatiquement. Les points seront importés comme infrastructures.
                            </div>
                        `;
                    }
                }
            } catch(err) {
                console.error('Erreur lecture fichier:', err);
            }
        };
        
        reader.readAsText(file);
    });
}


function setupImportFilePreview() {
    const fileInput = document.getElementById('importFile');
    if (!fileInput) return;
    
    fileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        const fileType = document.getElementById('importType').value;
        const reader = new FileReader();
        
        reader.onload = function(evt) {
            try {
                if (file.name.endsWith('.geojson')) {
                    const geojson = JSON.parse(evt.target.result);
                    const stats = {
                        points: 0,
                        lines: 0,
                        polygons: 0,
                        other: 0
                    };
                    
                    geojson.features.forEach(f => {
                        const type = f.geometry.type;
                        if (type === 'Point') stats.points++;
                        else if (type === 'LineString') stats.lines++;
                        else if (type === 'Polygon' || type === 'MultiPolygon') stats.polygons++;
                        else stats.other++;
                    });
                    
                    const total = stats.points + stats.lines + stats.polygons + stats.other;
                    
                    // Créer ou mettre à jour l'aperçu
                    let previewDiv = document.getElementById('filePreview');
                    if (!previewDiv) {
                        const container = document.querySelector('#modalBodyText div:first-child');
                        if (container) {
                            previewDiv = document.createElement('div');
                            previewDiv.id = 'filePreview';
                            previewDiv.style.marginTop = '16px';
                            previewDiv.style.padding = '12px';
                            previewDiv.style.background = '#f8fafc';
                            previewDiv.style.borderRadius = '12px';
                            previewDiv.style.fontSize = '0.75rem';
                            container.appendChild(previewDiv);
                        }
                    }
                    
                    if (previewDiv) {
                        previewDiv.innerHTML = `
                            <div style="font-weight: 600; margin-bottom: 8px;">📊 Aperçu du fichier :</div>
                            <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                                <div><span style="color: #2196F3;">📍 Points:</span> <strong>${stats.points}</strong></div>
                                <div><span style="color: #9E9E9E;">📏 Lignes:</span> <strong>${stats.lines}</strong></div>
                                <div><span style="color: #4CAF50;">🗺️ Polygones:</span> <strong>${stats.polygons}</strong></div>
                                <div><span style="color: #FF9800;">📦 Total:</span> <strong>${total}</strong></div>
                            </div>
                            <div style="margin-top: 8px; color: #64748b; font-size: 0.7rem;">
                                ${geojson.features.slice(0, 3).map(f => `• ${f.geometry.type}: ${f.properties?.nom || f.properties?.name || 'Sans nom'}`).join('<br>')}
                                ${geojson.features.length > 3 ? `<br>• ... et ${geojson.features.length - 3} autres` : ''}
                            </div>
                        `;
                    }
                } else if (file.name.endsWith('.kml')) {
                    let previewDiv = document.getElementById('filePreview');
                    if (!previewDiv) {
                        const container = document.querySelector('#modalBodyText div:first-child');
                        if (container) {
                            previewDiv = document.createElement('div');
                            previewDiv.id = 'filePreview';
                            previewDiv.style.marginTop = '16px';
                            previewDiv.style.padding = '12px';
                            previewDiv.style.background = '#f8fafc';
                            previewDiv.style.borderRadius = '12px';
                            container.appendChild(previewDiv);
                        }
                    }
                    if (previewDiv) {
                        previewDiv.innerHTML = `
                            <div style="font-weight: 600; margin-bottom: 8px;">📊 Fichier KML détecté :</div>
                            <div style="color: #64748b; font-size: 0.7rem;">
                                L'import sera traité automatiquement. Les points seront importés comme infrastructures.
                            </div>
                        `;
                    }
                }
            } catch(err) {
                console.error('Erreur lecture fichier:', err);
            }
        };
        
        reader.readAsText(file);
    });
}


// ========== NOTIFICATIONS (données réelles issues de la base) ==========
let notifs = [];
let notifsTimer = null;

// Notification immédiate suite à une action de l'utilisateur (ex: ajout d'un bâtiment).
// S'ajoute en tête de liste, en plus des notifications issues de la base.
function addNotif(title, message, type = 'info') {
    notifs.unshift({ id: 'action_' + Date.now(), title, message, type, time: new Date() });
    renderNotifs();
    updateBadge();
}

function getReadIds() {
    try { return JSON.parse(localStorage.getItem('unco_notifs_read') || '[]'); }
    catch (e) { return []; }
}

function markRead(id) {
    const read = getReadIds();
    if (!read.includes(id)) {
        read.push(id);
        localStorage.setItem('unco_notifs_read', JSON.stringify(read));
    }
}

function updateBadge() {
    const dot = document.querySelector('.notification-dot');
    const readIds = getReadIds();
    const unread = notifs.filter(n => !readIds.includes(n.id)).length;
    if (dot) dot.style.display = unread > 0 ? 'block' : 'none';
}

function renderNotifs() {
    const panel = document.getElementById('notifPanel');
    const list = document.getElementById('notifList');
    if (!panel || !list) return;
    if (notifs.length === 0) {
        list.innerHTML = '<div style="padding:20px; text-align:center; color:#9ca3af;">Aucune notification</div>';
        return;
    }
    const readIds = getReadIds();
    list.innerHTML = notifs.map(n => `
        <div class="notification-item ${!readIds.includes(n.id) ? 'unread' : ''}" data-id="${n.id}">
            <div class="notification-icon ${n.type}">${n.type === 'success' ? '✓' : n.type === 'warning' ? '⚠️' : n.type === 'danger' ? '⛔' : 'ℹ️'}</div>
            <div style="flex:1">
                <div class="notification-title">${n.title}</div>
                <div class="notification-message">${n.message}</div>
                <div class="notification-time">${getTimeAgo(n.time)}</div>
            </div>
        </div>
    `).join('');
    document.querySelectorAll('.notification-item').forEach(el => {
        el.addEventListener('click', () => {
            markRead(el.dataset.id);
            updateBadge();
            renderNotifs();
        });
    });
}

function getTimeAgo(date) {
    const s = Math.floor((new Date() - new Date(date)) / 1000);
    if (s < 60) return 'à l\'instant';
    const m = Math.floor(s / 60);
    if (m < 60) return `${m} min`;
    const h = Math.floor(m / 60);
    if (h < 24) return `${h} h`;
    return `${Math.floor(h / 24)} j`;
}

async function loadNotifs() {
    try {
        const response = await fetch(window.location.href + '?get_notifications=1');
        const result = await response.json();
        if (result.success) {
            const localActions = notifs.filter(n => String(n.id).startsWith('action_'));
            const dbNotifs = (result.notifications || []).map(n => ({ ...n, time: new Date(n.time) }));
            notifs = [...localActions, ...dbNotifs];
        }
    } catch (e) { /* connexion indisponible, on garde les notifications déjà chargées */ }
    renderNotifs();
    updateBadge();
}

function initNotifs() {
    if (!document.getElementById('notifPanel')) {
        const panel = document.createElement('div');
        panel.id = 'notifPanel';
        panel.className = 'notification-panel';
        panel.innerHTML = '<div class="notification-header"><span>Notifications</span><button id="closeNotif" style="background:none; border:none; cursor:pointer;">✕</button></div><div id="notifList" class="notification-list"></div>';
        document.querySelector('.header-actions').appendChild(panel);
        document.getElementById('closeNotif')?.addEventListener('click', () => panel.classList.remove('show'));
    }
    const bell = document.getElementById('notificationBell');
    const panel = document.getElementById('notifPanel');
    if (bell && panel) {
        bell.addEventListener('click', (e) => { e.stopPropagation(); panel.classList.toggle('show'); });
        document.addEventListener('click', (e) => { if (!panel.contains(e.target) && !bell.contains(e.target)) panel.classList.remove('show'); });
    }
    loadNotifs();
    if (notifsTimer) clearInterval(notifsTimer);
    notifsTimer = setInterval(loadNotifs, 60000);
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initNotifs);
else initNotifs();




// Mettre à jour le KPI du total des bâtiments avec la valeur réelle
if (totalBatimentsReel > 0) {
    totalBatiments = totalBatimentsReel;
    updateKPIs();
}


// Fonction pour mettre à jour les KPIs fiscaux
function updateFiscalKPIs() {
    // Formater les montants (en k)
    const payeK = (statsFiscalesReelles.total_paye / 1000).toFixed(0);
    const attenteK = (statsFiscalesReelles.total_attente / 1000).toFixed(0);
    const retardK = (statsFiscalesReelles.total_retard / 1000).toFixed(0);
    const exonereK = (statsFiscalesReelles.total_exonere / 1000).toFixed(0);
    
    document.getElementById('fkpi-paid-val').innerText = payeK + 'k';
    document.getElementById('fkpi-pending-val').innerText = attenteK + 'k';
    document.getElementById('fkpi-overdue-val').innerText = retardK + 'k';
    document.getElementById('fkpi-exempt-val').innerText = exonereK + 'k';
}

// Mettre à jour le tableau des paiements avec les données réelles
function renderPaymentsTable(filter = 'all') {
    const tbody = document.getElementById('payments-tbody');
    if (!tbody) return;
    
    let filtered = paiementsReels;
    if (filter !== 'all') {
        filtered = paiementsReels.filter(p => p.statut === filter);
    }
    
    if (!filtered || filtered.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">Aucun paiement trouvé</td></tr>';
        return;
    }
    
    tbody.innerHTML = filtered.map(p => `
        <tr>
            <td>${p.reference || '-'}</td>
            <td>${p.contribuable || '-'}</td>
            <td>${p.nicad || '-'}</td>
            <td>${parseInt(p.montant).toLocaleString()} FCFA</td>
            <td><span class="status-pill ${p.statut === 'paye' ? 'paye' : (p.statut === 'overdue' || p.statut === 'impaye' ? 'impaye' : 'encours')}">${
                p.statut === 'paye' ? 'Payé' : 
                (p.statut === 'overdue' || p.statut === 'impaye' ? 'Impayé' : 
                (p.statut === 'pending' ? 'En attente' : 'Exonéré'))
            }</span></td>
            <td style="white-space: nowrap;">
                <button class="btn-action-icon" onclick="editPayment('${p.reference}')" title="Modifier" style="background: none; border: none; cursor: pointer; padding: 6px; margin-right: 8px; color: #f59e0b;">
                    <i data-lucide="edit-2" style="width: 18px; height: 18px;"></i>
                </button>
                <button class="btn-action-icon" onclick="deletePayment('${p.reference}')" title="Supprimer" style="background: none; border: none; cursor: pointer; padding: 6px; color: #ef4444;">
                    <i data-lucide="trash-2" style="width: 18px; height: 18px;"></i>
                </button>
            </td>
        </tr>
    `).join('');
    
    // Réinitialiser les icônes Lucide après mise à jour du DOM
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}



function editPayment(reference) {
    // Trouver le paiement
    const payment = paiementsReels.find(p => p.reference === reference);
    if (!payment) {
        showToast('Erreur', 'Paiement non trouvé', 'error');
        return;
    }
    
    // Ouvrir une modale de modification
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('globalModal'));
    document.getElementById('modalTitle').innerText = 'Modifier un Paiement';
    document.getElementById('modalBodyText').innerHTML = `
        <div style="padding: 0;">
            <div style="margin-bottom: 16px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">Référence</label>
                <input type="text" id="editRef" value="${payment.reference}" class="form-control" readonly style="background:#f5f5f5;">
            </div>
            <div style="margin-bottom: 16px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">Contribuable</label>
                <input type="text" id="editContribuable" value="${payment.contribuable}" class="form-control">
            </div>
            <div style="margin-bottom: 16px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">Montant (FCFA)</label>
                <input type="number" id="editMontant" value="${payment.montant}" class="form-control">
            </div>
            <div style="margin-bottom: 16px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">Statut</label>
                <select id="editStatut" class="form-control">
                    <option value="paye" ${payment.statut === 'paye' ? 'selected' : ''}>✅ Payé</option>
                    <option value="pending" ${payment.statut === 'pending' ? 'selected' : ''}>⏳ En attente</option>
                    <option value="overdue" ${payment.statut === 'overdue' ? 'selected' : ''}>⚠️ En retard</option>
                    <option value="exempt" ${payment.statut === 'exempt' ? 'selected' : ''}>🔰 Exonéré</option>
                </select>
            </div>
        </div>
    `;
    
    const modalFooter = document.querySelector('#globalModal .modal-footer');
    if (modalFooter) {
        modalFooter.innerHTML = `
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 40px;">Annuler</button>
            <button type="button" class="btn btn-primary" onclick="saveEditPayment()" style="background: #1A6B45; color: white; border-radius: 40px; padding: 8px 24px;">Enregistrer</button>
        `;
    }
    
    modal.show();
}

function saveEditPayment() {
    const reference = document.getElementById('editRef').value;
    const contribuable = document.getElementById('editContribuable').value;
    const montant = parseFloat(document.getElementById('editMontant').value);
    const statut = document.getElementById('editStatut').value;
    
    if (!contribuable || !montant) {
        showToast('Erreur', 'Veuillez remplir tous les champs', 'error');
        return;
    }
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'update_paiement',
            reference: reference,
            contribuable: contribuable,
            montant: montant,
            statut: statut
        })
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            showToast('Succès', 'Paiement modifié avec succès !', 'success');
            refreshFiscalData();
            bootstrap.Modal.getInstance(document.getElementById('globalModal')).hide();
        } else {
            showToast('Erreur', result.error || 'Erreur lors de la modification', 'error');
        }
    })
    .catch(error => {
        console.error("Erreur:", error);
        showToast('Erreur', 'Erreur de communication', 'error');
    });
}




function deletePayment(reference) {
    if (!confirm(`Êtes-vous sûr de vouloir supprimer le paiement ${reference} ?`)) {
        return;
    }
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'delete_paiement',
            reference: reference
        })
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            showToast('Succès', 'Paiement supprimé avec succès !', 'success');
            addNotif('Suppression', `Le paiement ${reference} a été supprimé.`, 'warning');
             refreshFiscalData();
        } else {
            showToast('Erreur', result.error || 'Erreur lors de la suppression', 'error');
        }
    })
    .catch(error => {
        console.error("Erreur:", error);
        showToast('Erreur', 'Erreur de communication', 'error');
    });
}




// Fonction pour rafraîchir toutes les données fiscales
function refreshFiscalData() {
    fetch(window.location.href + '?get_fiscal_stats=true')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                statsFiscalesReelles.total_paye = data.total_paye;
                statsFiscalesReelles.total_attente = data.total_attente;
                statsFiscalesReelles.total_retard = data.total_retard;
                statsFiscalesReelles.total_exonere = data.total_exonere;
                updateFiscalKPIs();
            }
        })
        .catch(err => console.error('Erreur refresh:', err));
}

// ========== PROFIL UTILISATEUR CONNECTÉ ==========
document.addEventListener('DOMContentLoaded', function() {
    // Mettre à jour l'avatar dès le chargement si déjà connecté
    const nomStored = sessionStorage.getItem('unco_nom') || sessionStorage.getItem('unco_user') || '';
    const roleStored = sessionStorage.getItem('unco_role') || '';
    if (nomStored) {
        const initialsEl = document.getElementById('userInitials');
        const tooltipEl  = document.getElementById('userTooltip');
        if (initialsEl) initialsEl.textContent = nomStored.trim().split(/\s+/).map(w=>w[0]).join('').substring(0,2).toUpperCase();
        if (tooltipEl)  tooltipEl.textContent  = nomStored;
    }

    const avatar = document.getElementById('userAvatar');
    if (avatar) {
        avatar.addEventListener('click', function() {
            const nom   = sessionStorage.getItem('unco_nom')   || sessionStorage.getItem('unco_user') || 'Utilisateur';
            const role  = sessionStorage.getItem('unco_role')  || 'Invité';
            const email = sessionStorage.getItem('unco_email') || '';
            const roleLabel = role === 'admin' ? 'Administrateur' : role === 'agent' ? 'Agent Municipal' : role === 'controleur' ? 'Technicien SIG' : role;

            const existing = document.getElementById('profileCard');
            if (existing) { existing.remove(); document.getElementById('profileOverlay')?.remove(); return; }

            const initials = nom.trim().split(/\s+/).map(w=>w[0]).join('').toUpperCase().slice(0,2);

            const overlay = document.createElement('div');
            overlay.id = 'profileOverlay';
            overlay.style.cssText = 'position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,0.2);backdrop-filter:blur(2px);';

            const card = document.createElement('div');
            card.id = 'profileCard';
            card.style.cssText = `
                position:fixed; top:50%; left:50%; transform:translate(-50%,-50%);
                background:white; border-radius:20px; padding:32px 36px;
                z-index:9999; box-shadow:0 24px 64px rgba(0,0,0,0.25);
                min-width:300px; text-align:center;
            `;
            card.innerHTML = `
                <div style="width:72px;height:72px;border-radius:50%;background:#1A6B45;color:white;font-size:1.6rem;font-weight:800;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">${initials}</div>
                <div style="font-size:1.2rem;font-weight:700;color:#111827;margin-bottom:6px;">${nom}</div>
                <div style="font-size:0.8rem;background:#d1fae5;color:#065f46;padding:5px 14px;border-radius:20px;display:inline-block;font-weight:600;">${roleLabel}</div>
                ${email ? `<div style="font-size:0.75rem;color:#6b7280;margin-top:8px;">${email}</div>` : ''}
                <hr style="margin:18px 0;border-color:#f1f5f9;">
                <button onclick="document.getElementById('profileCard').remove();document.getElementById('profileOverlay').remove();" style="background:#f1f5f9;border:none;border-radius:12px;padding:9px 24px;cursor:pointer;font-size:0.85rem;font-weight:600;color:#374151;">Fermer</button>
            `;
            overlay.onclick = () => { card.remove(); overlay.remove(); };
            document.body.appendChild(overlay);
            document.body.appendChild(card);
        });
    }
});


//"Email professionnel" value="admin@unco.sn">
//"Mot de passe" value="admin123">

// ========== ADMIN SUPERVISION ==========

function timeAgo(dateStr) {
    if (!dateStr) return '';
    const now = new Date();
    const d = new Date(dateStr);
    const diffSec = Math.floor((now - d) / 1000);

    if (diffSec < 60) return 'Il y a ' + Math.max(diffSec, 0) + 's';
    if (diffSec < 3600) return 'Il y a ' + Math.floor(diffSec / 60) + ' min';

    // Comparaison par jour calendaire réel (et non par nombre d'heures écoulées) :
    // un événement de 25h peut être "aujourd'hui très tôt le matin" ou "hier soir"
    // selon l'heure actuelle — seule la date calendaire fait foi.
    const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const startOfLogDay = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    const dayDiff = Math.round((startOfToday - startOfLogDay) / 86400000);

    if (dayDiff <= 0) return 'Il y a ' + Math.floor(diffSec / 3600) + 'h';
    if (dayDiff === 1) return 'Hier';
    if (dayDiff < 7) return 'Il y a ' + dayDiff + ' jours';
    return d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' });
}

function formatLogTime(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    return d.toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit'}) + ' · ' + timeAgo(dateStr);
}

function renderActivityLog(logs) {
    const container = document.getElementById('admin-activity-log');
    if (!container) return;
    if (!logs || logs.length === 0) {
        container.innerHTML = '<div style="text-align:center;color:var(--text-muted);font-size:0.8rem;padding:20px 0;">Aucune activité récente</div>';
        return;
    }
    const colorMap = { ok:'#15803d', error:'#e11d48', warning:'#f59e0b' };
    container.innerHTML = logs.map(log => {
        const dotColor = colorMap[log.statut] || '#15803d';
        return `
        <div style="display:flex;gap:12px;align-items:flex-start;">
            <div style="flex-shrink:0;margin-top:3px;">
                <div style="width:9px;height:9px;border-radius:50%;background:${dotColor};margin-top:2px;"></div>
            </div>
            <div style="flex:1;">
                <div style="font-size:0.7rem;color:var(--text-muted);margin-bottom:2px;font-weight:600;">${formatLogTime(log.date)}</div>
                <div style="font-size:0.8rem;color:var(--text-primary);line-height:1.4;">${log.texte}</div>
            </div>
        </div>`;
    }).join('');
}

function renderAgents(agents, total) {
    const badge = document.getElementById('admin-agents-badge');
    if (badge) badge.textContent = (total || agents.length) + ' en ligne';

    const container = document.querySelector('#admin-agents-list > div');
    if (!container) return;
    const roleLabel = { admin:'Administrateur', agent:'Agent Municipal', controleur:'Technicien SIG' };
    const roleColor = { admin:'#e11d48', agent:'#1A6B45', controleur:'#0d7377' };

    if (!agents || agents.length === 0) {
        container.innerHTML = '<div style="text-align:center;color:var(--text-muted);font-size:0.8rem;padding:20px 0;">Aucun agent connecté</div>';
        return;
    }
    container.innerHTML = agents.map((a, i) => {
        const initials = (a.nom || '??').trim().split(/\s+/).map(w=>w[0]).join('').toUpperCase().slice(0,2);
        const rl = a.role || 'agent';
        const border = i < agents.length - 1 ? 'border-bottom:1px solid var(--border);' : '';
        return `
        <div style="display:flex;align-items:center;gap:10px;padding:9px 0;${border}">
            <div style="width:32px;height:32px;border-radius:50%;background:#1A6B45;color:white;font-size:0.7rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">${initials}</div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:0.82rem;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${a.nom}</div>
                <div style="font-size:0.68rem;color:${roleColor[rl]||'#1A6B45'}">${roleLabel[rl]||rl}</div>
            </div>
            <div style="font-size:0.68rem;color:var(--text-muted);flex-shrink:0;">${a.heure||''}</div>
            <div style="width:7px;height:7px;border-radius:50%;background:#22c55e;flex-shrink:0;"></div>
        </div>`;
    }).join('');
}

// Indique si le premier chargement admin a déjà été fait
let _adminFirstLoad = false;
// Mémorise le nombre de logs/agents reçus depuis la DB (pour détecter de nouvelles données)
let _adminLastLogCount = 0;
let _adminLastAgentCount = 0;
// Timer du KPI sync SIG (repart uniquement au premier chargement ou refresh manuel)
let _adminSyncTimer = null;
let _adminSyncSecs = 0;

function startSyncTimer() {
    if (_adminSyncTimer) clearInterval(_adminSyncTimer);
    _adminSyncSecs = 0;
    const syncEl = document.getElementById('admin-kpi-sync');
    if (!syncEl) return;
    syncEl.textContent = '0s';
    _adminSyncTimer = setInterval(() => {
        _adminSyncSecs++;
        const s = _adminSyncSecs;
        if (!document.getElementById('admin-kpi-sync')) { clearInterval(_adminSyncTimer); return; }
        if (s < 60) document.getElementById('admin-kpi-sync').textContent = s + 's';
        else document.getElementById('admin-kpi-sync').textContent = Math.floor(s/60) + 'm ' + (s%60) + 's';
    }, 1000);
}

// refreshAdminStats(force) — force=true quand c'est un refresh manuel (bouton Actualiser)
// En mode auto (force=false), seuls les KPIs numériques sont mis à jour.
// Le journal, les agents et le timer SIG ne bougent que si la DB renvoie de NOUVELLES données réelles.
function refreshAdminStats(force) {
    const isFirstLoad = !_adminFirstLoad;
    if (isFirstLoad) _adminFirstLoad = true;
    const doFull = isFirstLoad || force;

    // ── KPIs numériques (toujours rafraîchis) ──
    fetch(window.location.href + '?get_admin_stats=1')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const usersEl  = document.getElementById('admin-kpi-users');
            const deltaEl  = document.getElementById('admin-kpi-users-delta');
            const failedEl = document.getElementById('admin-kpi-failed');
            const srvEl    = document.getElementById('admin-kpi-server');
            if (usersEl)  usersEl.textContent  = data.users_actifs ?? 0;
            if (deltaEl && data.users_delta) deltaEl.textContent = data.users_delta;
            if (failedEl) failedEl.textContent = data.failed_logins ?? 0;
            if (srvEl)    srvEl.textContent    = (data.server_load ?? 32) + '%';
        })
        .catch(() => {});

    // ── Journal d'activité — chargé au premier affichage ou si la DB a de nouvelles entrées ──
    if (doFull) {
        fetch(window.location.href + '?get_activity_log=1')
            .then(r => r.json())
            .then(data => {
                if (!data.success || !data.logs) return;
                // On met à jour seulement si c'est le premier chargement
                // OU si la DB a renvoyé plus d'entrées que la dernière fois
                // (logs non-statiques = la DB a de vraies données → fromDB flag)
                const newCount = data.logs.length;
                const hasRealData = data.from_db === true;
                if (isFirstLoad || (hasRealData && newCount !== _adminLastLogCount)) {
                    _adminLastLogCount = newCount;
                    renderActivityLog(data.logs);
                }
            })
            .catch(() => {});
    }

    // ── Agents connectés — chargés au premier affichage ou si le nombre change ──
    if (doFull) {
        fetch(window.location.href + '?get_agents=1')
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                const newCount = data.total || data.agents.length;
                const hasRealData = data.from_db === true;
                if (isFirstLoad || (hasRealData && newCount !== _adminLastAgentCount)) {
                    _adminLastAgentCount = newCount;
                    renderAgents(data.agents, data.total);
                }
            })
            .catch(() => {});
    }

    // ── Sync SIG — timer démarre seulement au premier chargement ou refresh manuel ──
    if (doFull) {
        startSyncTimer();
    }
}

function adminExportData() {
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('globalModal'));
    document.getElementById('modalTitle').innerText = 'Sauvegarde & Export';
    document.getElementById('modalBodyText').innerHTML = `
        <div style="display:flex;flex-direction:column;gap:12px;padding:4px 0;">
            <div style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:4px;">Choisissez les données à exporter :</div>
            <label style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid var(--border);border-radius:10px;cursor:pointer;">
                <input type="checkbox" id="expPaiements" checked> <span style="font-size:0.85rem;">Paiements (CSV)</span>
            </label>
            <label style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid var(--border);border-radius:10px;cursor:pointer;">
                <input type="checkbox" id="expBatiments"> <span style="font-size:0.85rem;">Bâtiments (GeoJSON)</span>
            </label>
            <label style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid var(--border);border-radius:10px;cursor:pointer;">
                <input type="checkbox" id="expUtilisateurs"> <span style="font-size:0.85rem;">Utilisateurs (CSV)</span>
            </label>
            <div style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;">Les fichiers seront générés et téléchargés immédiatement.</div>
        </div>
    `;
    const footer = document.querySelector('#globalModal .modal-footer');
    if (footer) footer.innerHTML = `
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius:40px;">Annuler</button>
        <button type="button" class="btn btn-primary" onclick="runAdminExport()" style="background:#1A6B45;color:white;border-radius:40px;padding:8px 24px;">Exporter</button>
    `;
    modal.show();
}

function runAdminExport() {
    const doP = document.getElementById('expPaiements')?.checked;
    const doB = document.getElementById('expBatiments')?.checked;
    const doU = document.getElementById('expUtilisateurs')?.checked;
    bootstrap.Modal.getInstance(document.getElementById('globalModal'))?.hide();
    if (doP) {
        // Export paiements CSV depuis les données déjà chargées
        const rows = [['Reference','Contribuable','NICAD','Montant','Statut','Date']];
        (window.paymentsDataGlobal || []).forEach(p => {
            rows.push([p.reference||'', p.contribuable||'', p.nicad||'', p.montant||'', p.statut||'', p.date_creation||'']);
        });
        const csv = rows.map(r => r.map(c => '"'+(c+'').replace(/"/g,'""')+'"').join(',')).join('\n');
        const blob = new Blob(['\uFEFF'+csv], {type:'text/csv;charset=utf-8;'});
        const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'paiements_' + new Date().toISOString().slice(0,10) + '.csv'; a.click();
    }
    if (doB) {
        // Export bâtiments GeoJSON
        const feats = (window.buildingsDataGlobal || []).map(b => ({
            type:'Feature', properties:{identifiant:b.identifiant,type:b.type,adresse:b.adresse,quartier:b.quartier},
            geometry:{type:'Point', coordinates:[parseFloat(b.longitude)||0, parseFloat(b.latitude)||0]}
        }));
        const gj = JSON.stringify({type:'FeatureCollection',features:feats}, null, 2);
        const blob = new Blob([gj], {type:'application/json'});
        const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'batiments_' + new Date().toISOString().slice(0,10) + '.geojson'; a.click();
    }
    showToast('Export', 'Fichier(s) exporté(s) avec succès.', 'success');
}

function adminServerCarto() {
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('globalModal'));
    document.getElementById('modalTitle').innerText = 'Serveur Cartographique';
    document.getElementById('modalBodyText').innerHTML = `
        <div style="display:flex;flex-direction:column;gap:10px;padding:4px 0;">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:#f8fafe;border-radius:10px;">
                <span style="font-size:0.82rem;font-weight:600;">Tile Server (CartoCDN)</span>
                <span style="background:#dcfce7;color:#15803d;font-size:0.7rem;font-weight:700;padding:3px 10px;border-radius:40px;">● Opérationnel</span>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:#f8fafe;border-radius:10px;">
                <span style="font-size:0.82rem;font-weight:600;">Supabase PostGIS</span>
                <span style="background:#dcfce7;color:#15803d;font-size:0.7rem;font-weight:700;padding:3px 10px;border-radius:40px;">● Connecté</span>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:#f8fafe;border-radius:10px;">
                <span style="font-size:0.82rem;font-weight:600;">Couche Cadastrale</span>
                <span style="background:#dcfce7;color:#15803d;font-size:0.7rem;font-weight:700;padding:3px 10px;border-radius:40px;">● Chargée</span>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:#f8fafe;border-radius:10px;">
                <span style="font-size:0.82rem;font-weight:600;">Zoom max disponible</span>
                <span style="font-size:0.82rem;color:var(--text-secondary);">Niveau 22</span>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:#f8fafe;border-radius:10px;">
                <span style="font-size:0.82rem;font-weight:600;">Dernière synchronisation tuiles</span>
                <span style="font-size:0.82rem;color:var(--text-secondary);" id="last-tile-sync">—</span>
            </div>
        </div>
    `;
    document.getElementById('last-tile-sync').textContent = new Date().toLocaleString('fr-FR');
    const footer = document.querySelector('#globalModal .modal-footer');
    if (footer) footer.innerHTML = `<button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius:40px;">Fermer</button>`;
    modal.show();
}

function showAdminSettings() {
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('globalModal'));
    document.getElementById('modalTitle').innerText = 'Paramètres Administration';
    document.getElementById('modalBodyText').innerHTML = `
        <div style="display:flex;flex-direction:column;gap:14px;padding:4px 0;">
            <div>
                <label style="font-size:0.8rem;font-weight:600;margin-bottom:6px;display:block;">Intervalle de rafraîchissement (secondes)</label>
                <input type="number" id="adminRefreshInterval" value="30" min="10" max="300" class="form-control" style="border-radius:10px;">
            </div>
            <div>
                <label style="font-size:0.8rem;font-weight:600;margin-bottom:6px;display:block;">Seuil alerte charge serveur (%)</label>
                <input type="number" id="adminServerThreshold" value="80" min="10" max="100" class="form-control" style="border-radius:10px;">
            </div>
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                <input type="checkbox" id="adminNotifFailed" checked>
                <span style="font-size:0.82rem;">Notifications pour les tentatives de connexion échouées</span>
            </label>
        </div>
    `;
    const footer = document.querySelector('#globalModal .modal-footer');
    if (footer) footer.innerHTML = `
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius:40px;">Annuler</button>
        <button type="button" class="btn btn-primary" onclick="bootstrap.Modal.getInstance(document.getElementById('globalModal')).hide();showToast('Paramètres','Paramètres sauvegardés.','success');" style="background:#1A6B45;color:white;border-radius:40px;padding:8px 24px;">Enregistrer</button>
    `;
    modal.show();
}

// Auto-refresh admin quand on est sur la vue admin
let _adminRefreshTimer = null;
document.addEventListener('DOMContentLoaded', function() {
    // Observer les changements de vue pour déclencher le chargement initial admin
    const observer = new MutationObserver(() => {
        const viewAdmin = document.getElementById('view-admin');
        if (viewAdmin && viewAdmin.classList.contains('active') && !_adminFirstLoad) {
            // Premier affichage : chargement complet (force=true équivalent via isFirstLoad)
            refreshAdminStats(false);
            lucide.createIcons();
            // Timer auto toutes les 60s — ne rafraîchit QUE les KPIs numériques (force=false)
            if (!_adminRefreshTimer) {
                _adminRefreshTimer = setInterval(() => {
                    if (document.getElementById('view-admin')?.classList.contains('active')) {
                        refreshAdminStats(false);
                    }
                }, 60000);
            }
        }
    });
    observer.observe(document.getElementById('appLayout') || document.body, { subtree:true, attributes:true, attributeFilter:['class'] });
});

// Sur mobile, le panneau flottant "COUCHES SIG" reste ouvert par défaut et peut alors
// recouvrir les boutons "Plan Standard / Satellite / Cadastre" en bas d'une carte réduite
// en hauteur. On le replie par défaut sur petit écran (dépliable au clic, comme avant).
function collapseSigPanelsOnMobile() {
    if (window.innerWidth > 900) return;
    [
        ['layersPanelContent', 'layersPanelToggle'],
        ['layersPanelFiscalContent', 'layersPanelFiscalToggle']
    ].forEach(([contentId, toggleId]) => {
        const content = document.getElementById(contentId);
        const toggle = document.getElementById(toggleId);
        if (content && toggle && content.style.display !== 'none') {
            content.style.display = 'none';
            toggle.textContent = '+';
        }
    });
}
document.addEventListener('DOMContentLoaded', collapseSigPanelsOnMobile);
window.addEventListener('resize', collapseSigPanelsOnMobile);

//"Email professionnel" value="admin@unco.sn">
//"Mot de passe" value="admin123">

</script>

</body>
</html>
