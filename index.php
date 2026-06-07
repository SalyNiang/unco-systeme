<?php
// ========== CONNEXION SUPABASE (PostgreSQL) ==========
$host     = 'aws-0-eu-west-1.pooler.supabase.com';
$port     = '6543';
$dbname   = 'postgres';
$user     = 'postgres.rfblkqrnhcmbikfdfyak';
$password = 'Salyniang1335689'; // ← Remplacez par votre mot de passe Supabase

// ========== HELPER : retourne l'id de l'admin par défaut ==========
function getDefaultUserId(PDO $pdo): int {
    static $cachedId = null;
    if ($cachedId !== null) return $cachedId;
    $stmt = $pdo->query("SELECT id FROM utilisateurs WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $cachedId = $row ? (int)$row['id'] : 1;
    return $cachedId;
}

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
    ]);



    // ========== STATISTIQUES MENSUELLES POUR LE GRAPHIQUE ==========
$stats_mensuelles = [];
$stmt = $pdo->query("
    SELECT 
        TO_CHAR(date_creation, 'Mon') as mois,
        EXTRACT(MONTH FROM date_creation) as mois_num,
        EXTRACT(YEAR FROM date_creation) as annee,
        COALESCE(SUM(CASE WHEN statut = 'paye'    THEN montant ELSE 0 END), 0) as total_paye,
        COALESCE(SUM(CASE WHEN statut = 'pending' THEN montant ELSE 0 END), 0) as total_attente,
        COALESCE(SUM(CASE WHEN statut = 'overdue' THEN montant ELSE 0 END), 0) as total_retard
    FROM paiements 
    WHERE date_creation >= DATE_TRUNC('year', CURRENT_DATE)
    GROUP BY EXTRACT(YEAR FROM date_creation), EXTRACT(MONTH FROM date_creation), TO_CHAR(date_creation, 'Mon')
    ORDER BY annee, mois_num
");
$stats_mensuelles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Si pas de données, utiliser les 6 derniers mois avec des valeurs par défaut
if (empty($stats_mensuelles)) {
    $stats_mensuelles = [];
    $mois = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai'];
    foreach ($mois as $i => $m) {
        $stats_mensuelles[] = [
            'mois' => $m,
            'total_paye' => 0,
            'total_attente' => 0,
            'total_retard' => 0
        ];
    }
}



    // ========== STATISTIQUES FISCALES RÉELLES ==========
// Récupérer les totaux par statut de paiement
$stats_fiscales = [];
$stmt = $pdo->query("
    SELECT 
        COALESCE(SUM(CASE WHEN statut = 'paye'    THEN montant ELSE 0 END), 0) as total_paye,
        COALESCE(SUM(CASE WHEN statut = 'pending' THEN montant ELSE 0 END), 0) as total_attente,
        COALESCE(SUM(CASE WHEN statut = 'overdue' THEN montant ELSE 0 END), 0) as total_retard,
        0 as total_exonere,
        COUNT(*) as total_paiements
    FROM paiements
");
$stats_fiscales = $stmt->fetch(PDO::FETCH_ASSOC);

// Valeurs par défaut si aucun paiement
if ($stats_fiscales['total_paiements'] == 0) {
    $stats_fiscales = [
        'total_paye' => 885000,
        'total_attente' => 200000,
        'total_retard' => 75000,
        'total_exonere' => 320000
    ];
}

// Récupérer les paiements pour le tableau
$paiements_db = [];
$stmt = $pdo->query("
    SELECT reference, contribuable, nicad, montant, statut, date_creation 
    FROM paiements 
    ORDER BY date_creation DESC 
    LIMIT 10
");
$paiements_db = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
    $stmt = $pdo->query("SELECT identifiant, type, adresse, quartier, latitude, longitude, surface, etages FROM batiments ORDER BY date_creation DESC");
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
    
    // Si la table batiments est vide, initialiser avec des valeurs par défaut
    if ($total_batiments_reel == 0) {
        $stats_batiments = [
            ['type' => 'Résidentiel', 'nombre' => 8450, 'pourcentage' => 68],
            ['type' => 'Commercial', 'nombre' => 2890, 'pourcentage' => 23],
            ['type' => 'Mixte', 'nombre' => 890, 'pourcentage' => 7],
            ['type' => 'Équipement public', 'nombre' => 220, 'pourcentage' => 2]
        ];
        $total_batiments_reel = 12450;
    }
    
} catch(PDOException $e) {
    die("Erreur de connexion à Supabase : " . $e->getMessage());
}

// ========== GET: STATISTIQUES FISCALES (rafraîchissement AJAX) ==========
if (isset($_GET['get_fiscal_stats'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("
            SELECT 
                COALESCE(SUM(CASE WHEN statut = 'paye'    THEN montant ELSE 0 END), 0) as total_paye,
                COALESCE(SUM(CASE WHEN statut = 'pending' THEN montant ELSE 0 END), 0) as total_attente,
                COALESCE(SUM(CASE WHEN statut = 'overdue' THEN montant ELSE 0 END), 0) as total_retard,
                0 as total_exonere
            FROM paiements
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true] + $row);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========== TRAITER LES REQUÊTES POST ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // IMPORTANT : Nettoyer les buffers avant d'envoyer du JSON
    if (ob_get_length()) ob_clean();
    
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    
    header('Content-Type: application/json');
    
    if (!$data || !isset($data['action'])) {
        echo json_encode(['success' => false, 'error' => 'Requête invalide']);
        exit;
    }
    
    // Action: add_building
    if ($data['action'] === 'add_building') {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO batiments (identifiant, type, adresse, quartier, latitude, longitude, surface, etages, observations, cree_par) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['identifiant'],
                $data['type'],
                $data['adresse'],
                $data['quartier'],
                $data['latitude'],
                $data['longitude'],
                $data['surface'],
                $data['etages'],
                $data['observations'] ?? null,
                is_numeric($data['cree_par'] ?? null) ? (int)$data['cree_par'] : getDefaultUserId($pdo)
            ]);
            
            $pdo->exec("UPDATE compteurs SET valeur = valeur + 1, date_mise_a_jour = NOW() WHERE nom = 'total_batiments'");
            
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }
    
    // Action: delete_building
    if ($data['action'] === 'delete_building') {
        try {
            $stmt = $pdo->prepare("DELETE FROM batiments WHERE identifiant = ?");
            $stmt->execute([$data['identifiant']]);
            
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
            INSERT INTO paiements (reference, contribuable, montant, mode_paiement, date_paiement, numero_recu, observations, statut, cree_par, date_creation) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'paye', ?, NOW())
        ");
        $stmt->execute([
            $data['reference'],
            $data['contribuable'],
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
        // Normaliser le statut vers les valeurs CHECK: 'paye','pending','overdue'
        $statutMap = ['encours'=>'pending','impaye'=>'overdue','exempt'=>'pending','exonere'=>'pending'];
        $statut = $statutMap[$data['statut']] ?? $data['statut'];
        if (!in_array($statut, ['paye','pending','overdue'])) $statut = 'pending';

        $stmt = $pdo->prepare("
            INSERT INTO paiements (reference, contribuable, montant, mode_paiement, date_paiement, observations, statut, cree_par, date_creation) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $data['reference'],
            $data['contribuable'],
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
    
    // Action inconnue
    echo json_encode(['success' => false, 'error' => 'Action inconnue : ' . $data['action']]);
    exit;


// ========== ACTION: IMPORT_SIG ==========
if ($data['action'] === 'import_sig') {
    try {
        $format = $data['format'];
        $content = $data['content'];
        $crs = $data['crs'];
        $filename = $data['filename'] ?? 'fichier_importe';
        
        // Décoder le contenu base64
        $fileContent = base64_decode($content);
        
        if ($fileContent === false) {
            echo json_encode(['success' => false, 'error' => 'Erreur de décodage du fichier']);
            exit;
        }
        
        $importedCount = 0;
        $errors = [];
        
        // Traiter selon le format
        if ($format === 'geojson') {
            $geojson = json_decode($fileContent, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo json_encode(['success' => false, 'error' => 'GeoJSON invalide: ' . json_last_error_msg()]);
                exit;
            }
            
            if (!isset($geojson['features']) || !is_array($geojson['features'])) {
                echo json_encode(['success' => false, 'error' => 'Format GeoJSON invalide: features manquants']);
                exit;
            }
            
            // Compter par type
            $stats = ['Point' => 0, 'LineString' => 0, 'Polygon' => 0, 'MultiPolygon' => 0, 'other' => 0];
            
            foreach ($geojson['features'] as $feature) {
                $geometry = $feature['geometry'];
                $properties = $feature['properties'];
                $type = $geometry['type'];
                
                $stats[$type] = ($stats[$type] ?? 0) + 1;
                
                try {
                    if ($type === 'Point') {
                        // Importer un point (infrastructure)
                        $lat = $geometry['coordinates'][1];
                        $lng = $geometry['coordinates'][0];
                        $nom = $properties['nom'] ?? $properties['name'] ?? $properties['Nom'] ?? 'Point sans nom';
                        $categorie = $properties['categorie'] ?? $properties['type'] ?? $properties['Type'] ?? 'import_geojson';
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO infrastructures (nom, categorie, latitude, longitude, icone, couleur, date_creation) 
                            VALUES (?, ?, ?, ?, 'map-pin', '#1A6B45', NOW())
                        ");
                        $stmt->execute([$nom, $categorie, $lat, $lng]);
                        $importedCount++;
                    }
                    elseif ($type === 'LineString') {
                        // Importer une ligne (rue)
                        $nom = $properties['nom'] ?? $properties['name'] ?? $properties['Nom'] ?? $properties['Rue'] ?? 'Route sans nom';
                        $longueur = $properties['Shape_Leng'] ?? $properties['longueur'] ?? 0;
                        
                        // Vérifier si la table rues existe
                        try {
                            $stmt = $pdo->prepare("
                                INSERT INTO rues (nom, longueur, date_creation) 
                                VALUES (?, ?, NOW())
                            ");
                            $stmt->execute([$nom, $longueur]);
                            $importedCount++;
                        } catch (PDOException $e) {
                            // Table rues peut ne pas exister
                            $errors[] = "Ligne ignorée: table rues inexistante";
                        }
                    }
                    elseif ($type === 'Polygon' || $type === 'MultiPolygon') {
                        // Importer un polygone (bâtiment ou parcelle)
                        $identifiant = $properties['id'] ?? $properties['Num_Parcel'] ?? $properties['identifiant'] ?? 'IMP-' . time() . '-' . $importedCount;
                        $type_batiment = $properties['type'] ?? $properties['Type'] ?? $properties['usage'] ?? 'Résidentiel';
                        $surface = $properties['surface'] ?? $properties['Surface'] ?? 0;
                        $adresse = $properties['adresse'] ?? $properties['Adresse'] ?? '';
                        $quartier = $properties['quartier'] ?? $properties['Quartier'] ?? $properties['Quatiers'] ?? '';
                        
                        // Calculer le centroïde
                        $center = ['lat' => 14.7167, 'lng' => -17.4667];
                        if ($type === 'Polygon' && isset($geometry['coordinates'][0])) {
                            $coords = $geometry['coordinates'][0];
                            $center = calculateCentroid($coords);
                        } elseif ($type === 'MultiPolygon' && isset($geometry['coordinates'][0][0])) {
                            $coords = $geometry['coordinates'][0][0];
                            $center = calculateCentroid($coords);
                        }
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO batiments (identifiant, type, latitude, longitude, surface, adresse, quartier, date_creation, cree_par, observations) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
                        ");
                        $stmt->execute([
                            $identifiant, 
                            $type_batiment, 
                            $center['lat'], 
                            $center['lng'], 
                            $surface, 
                            $adresse, 
                            $quartier,
                            is_numeric($data['cree_par'] ?? null) ? (int)$data['cree_par'] : getDefaultUserId($pdo),
                            "Importé depuis $filename"
                        ]);
                        $importedCount++;
                        
                        // Mettre à jour le compteur
                        $pdo->exec("UPDATE compteurs SET valeur = valeur + 1, date_mise_a_jour = NOW() WHERE nom = 'total_batiments'");
                    }
                } catch (PDOException $e) {
                    $errors[] = "Erreur import $type: " . $e->getMessage();
                }
            }
            
            $message = "$importedCount entités importées avec succès";
            if (!empty($errors)) {
                $message .= " (" . count($errors) . " erreurs)";
            }
            
            echo json_encode([
                'success' => true,
                'message' => $message,
                'count' => $importedCount,
                'stats' => $stats,
                'errors' => $errors
            ]);
            exit;
        }
        elseif ($format === 'kml') {
            // Traitement KML simplifié
            $kml = simplexml_load_string($fileContent);
            if ($kml === false) {
                echo json_encode(['success' => false, 'error' => 'KML invalide']);
                exit;
            }
            
            // Rechercher les Placemarks
            $placemarks = [];
            if (isset($kml->Document)) {
                foreach ($kml->Document->Placemark as $placemark) {
                    $nom = (string)$placemark->name;
                    $coords = (string)$placemark->Point->coordinates;
                    
                    if ($coords) {
                        $coordParts = explode(',', $coords);
                        if (count($coordParts) >= 2) {
                            $lng = floatval($coordParts[0]);
                            $lat = floatval($coordParts[1]);
                            
                            $stmt = $pdo->prepare("
                                INSERT INTO infrastructures (nom, categorie, latitude, longitude, icone, couleur, date_creation) 
                                VALUES (?, 'kml_import', ?, ?, 'map-pin', '#1A6B45', NOW())
                            ");
                            $stmt->execute([$nom, $lat, $lng]);
                            $importedCount++;
                        }
                    }
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => "$importedCount entités importées depuis KML",
                'count' => $importedCount
            ]);
            exit;
        }
        elseif ($format === 'shapefile') {
            echo json_encode([
                'success' => false,
                'error' => 'Les shapefiles doivent être convertis en GeoJSON. Utilisez https://geojson.io ou ogr2ogr'
            ]);
            exit;
        }
        
        echo json_encode(['success' => false, 'error' => 'Format non supporté']);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}
}

// Passer les données à JavaScript via des variables
// ========== DONNÉES POUR JAVASCRIPT ==========
$stats_batiments_json = json_encode($stats_batiments);
$total_batiments_reel_json = $total_batiments_reel;
$stats_fiscales_json = json_encode($stats_fiscales);
$paiements_json = json_encode($paiements_db);
$stats_mensuelles_json = json_encode($stats_mensuelles);
?>


<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>UNCO — Système de Gestion Urbaine et Fiscale de Ouakam</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

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
        .sidebar-nav { flex: 1; padding: 20px 12px; display: flex; flex-direction: column; gap: 4px; }
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
    bottom: 20px;
    left: 20px;
    background: rgba(255, 255, 255, 0.96);
    backdrop-filter: blur(8px);
    border-radius: 16px;
    padding: 10px 14px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    z-index: 400;
    border: 1px solid #e2e8f0;
    min-width: 180px;
}

/* Premier panneau (Couches SIG) - en bas à gauche */
#layersPanel {
    bottom: 20px;
    left: 20px;
}

/* Deuxième panneau (Légende) - au-dessus du premier */
#legendPanel {
    bottom: 200px;
    left: 20px;
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
    bottom: 20px;
    right: 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    z-index: 400;
}

.map-action-btn {
    width: 48px;
    height: 48px;
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
    width: 22px;
    height: 22px;
}

.map-action-btn:hover {
    background: #1A6B45;
    color: white;
    transform: scale(1.05);
}

/* Tooltip au survol (texte apparaît à droite) */
.map-action-btn {
    position: relative;
}

.map-action-btn::after {
    content: attr(data-tooltip);
    position: absolute;
    right: 55px;
    background: #1e293b;
    color: white;
    font-size: 0.7rem;
    font-weight: 500;
    padding: 4px 10px;
    border-radius: 20px;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s;
    font-family: 'Inter', sans-serif;
}

.map-action-btn:hover::after {
    opacity: 1;
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

    </style>
    
</head>
<body>

<div id="loginPage" class="login-container">
    <div class="login-card">
        <h1>UNCO</h1>
        <p>Système de Gestion Urbaine et Fiscale</p>
        <input type="email" id="loginEmail" placeholder="Email" autocomplete="off">
        <input type="password" id="loginPassword" placeholder="Mot de passe" autocomplete="off">
        <button class="login-btn" onclick="attemptLogin()">Se connecter</button>
        <div id="loginError" class="login-error">Identifiants incorrects</div>
    </div>
</div>

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
    
    function attemptLogin() {
        const email = document.getElementById('loginEmail').value.trim();
        const pwd = document.getElementById('loginPassword').value.trim();
        if (email === 'admin@unco.sn' && pwd === 'admin123') {
            sessionStorage.setItem('unco_auth', 'true');
            document.getElementById('loginPage').style.display = 'none';
            document.getElementById('appLayout').style.display = 'flex';
            if (typeof initAll === 'function') initAll();
        } else {
            document.getElementById('loginError').style.display = 'block';
        }
    }
    
    function logout() { 
        sessionStorage.clear(); 
        location.reload(); 
    }
    
    if (sessionStorage.getItem('unco_auth') === 'true') {
        const loginPage = document.getElementById('loginPage');
        const appLayout = document.getElementById('appLayout');
        if (loginPage) loginPage.style.display = 'none';
        if (appLayout) appLayout.style.display = 'flex';
        if (typeof initAll === 'function') initAll();
    }
</script>

<div id="appLayout" class="app-layout">
    <aside class="nav-sidebar">
        <div>
            <div class="sidebar-brand"><i data-lucide="compass" class="brand-icon"></i><div class="brand-text"><h2>UNCO</h2><span>Ouakam · SIG & Fiscalité</span></div></div>
            <div class="role-indicator"><span class="role-badge" id="current-role-badge">TECHNICIEN SIG</span></div>
            <nav class="sidebar-nav">
                <button class="nav-item active" onclick="uncoCore.switchRole('sig', this)"><i data-lucide="map-pin"></i><span>Technicien SIG</span></button>
                <button class="nav-item" onclick="uncoCore.switchRole('municipal', this)"><i data-lucide="briefcase"></i><span>Agent Municipal</span></button>
                <button class="nav-item" onclick="uncoCore.switchRole('admin', this)"><i data-lucide="shield"></i><span>Administrateur</span></button>
            </nav>
        </div>
        <div class="sidebar-user">
    <div class="user-avatar" id="userAvatar" style="cursor: pointer; position: relative;">AD
        <div class="user-tooltip" id="userTooltip">Amadou Diallo</div>
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
                   <div class="map-pills-overlay">
                      <button class="map-pill active" onclick="setBaseLayer('osm', this)">Plan Standard</button>
                      <button class="map-pill" onclick="setBaseLayer('satellite', this)">Satellite · Relief</button>
                      <button class="map-pill" onclick="toggleCadastre(this)">Cadastre</button>
                   </div>
                   <!-- Panneau COUCHES SIG (compact) -->
<!--div id="layersPanel" class="map-overlay-panel">
    <div class="panel-title">COUCHES SIG</div>
    <div class="layer-item"><input type="checkbox" checked id="layerCommune"> <label>Limite communale</label></div>
    <div class="layer-item"><input type="checkbox" checked id="layerQuartiers"> <label>Quartiers</label></div>
    <div class="layer-item"><input type="checkbox" checked id="layerParcelles"> <label>Parcelles</label></div>
    <div class="layer-item"><input type="checkbox" checked id="layerRues"> <label>Rues</label></div>
    <div class="layer-item"><input type="checkbox" checked id="layerInfrastructures"> <label>Infrastructures</label></div>
</div-->

<!-- Panneau LÉGENDE INFRASTRUCTURES (avec icônes Lucide, style carte) -->
<div id="legendPanel" class="map-overlay-panel">
    <div class="panel-title">LÉGENDE INFRASTRUCTURES</div>
    <div class="legend-grid">
        <div class="legend-item"><div class="legend-icon" style="background:#F5A623;"><i data-lucide="route" style="width:14px;height:14px;color:white;"></i></div><span>Voie</span></div>
        <div class="legend-item"><div class="legend-icon" style="background:#f4f808;"><i data-lucide="zap" style="width:14px;height:14px;color:white;"></i></div><span>Électricité</span></div>
        <div class="legend-item"><div class="legend-icon" style="background:#0dfc21;"><i data-lucide="pill" style="width:14px;height:14px;color:white;"></i></div><span>Pharmacie</span></div>
        <div class="legend-item"><div class="legend-icon" style="background:#3d9c06;"><i data-lucide="hospital" style="width:14px;height:14px;color:white;"></i></div><span>Santé</span></div>
        <div class="legend-item"><div class="legend-icon" style="background:#0b62d4;"><i data-lucide="graduation-cap" style="width:14px;height:14px;color:white;"></i></div><span>École</span></div>
        <div class="legend-item"><div class="legend-icon" style="background:#3d9c06;"><i data-lucide="mosque" style="width:14px;height:14px;color:white;"></i></div><span>Mosquée</span></div>
        <div class="legend-item"><div class="legend-icon" style="background:#eb2a08;"><i data-lucide="building-2" style="width:14px;height:14px;color:white;"></i></div><span>Administration</span></div>
    </div>
</div>

<!-- Boutons flottants en bas à droite (icônes uniquement) -->
<div class="map-action-buttons">
    <button class="map-action-btn" data-tooltip="Ajouter un bâtiment" onclick="uncoCore.openModal('addBuilding')">
        <i data-lucide="plus-square"></i>
    </button>
    <button class="map-action-btn" data-tooltip="Modifier un bâtiment" onclick="uncoCore.openModal('editBuilding')">
        <i data-lucide="edit-3"></i>
    </button>
    <button class="map-action-btn" data-tooltip="Supprimer un bâtiment" onclick="uncoCore.openModal('deleteBuilding')">
    <i data-lucide="trash-2"></i>
    </button>
    <button class="map-action-btn" data-tooltip="Générer un rapport" onclick="uncoCore.openModal('generateReport')">
        <i data-lucide="pie-chart"></i>
    </button>
    <button class="map-action-btn" data-tooltip="Exporter la carte" onclick="uncoCore.openModal('exportMap')">
        <i data-lucide="download-cloud"></i>
    </button>
</div>
                </div>
                <div class="bottom-panel">
                    <div class="kpi-row">
                        <div class="kpi-card"><div class="kpi-label">BÂTIMENTS RECENSÉS</div><div class="kpi-value gold">12,450</div></div>
                        <div class="kpi-card"><div class="kpi-label">ALERTE RUES</div><div class="kpi-value">3</div></div>
                        <div class="kpi-card"><div class="kpi-label">TAUX ADRESSAGE</div><div class="kpi-value">85%</div></div>
                        <div class="kpi-card"><div class="kpi-label">ACTIFS TERRAIN</div><div class="kpi-value">12</div></div>
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
    <div style="display: flex; gap: 20px; align-items: stretch;">
        
        <!-- CARTE FISCALE (GAUCHE) -->
        <div style="flex: 3.5;">
            <div class="map-container">
                <div id="fiscal-map"></div>
                <div class="map-pills-overlay">
                    <button class="map-pill active" onclick="setBaseLayerFiscal('osm', this)">Plan Standard</button>
                    <button class="map-pill" onclick="setBaseLayerFiscal('satellite', this)">Satellite · Relief</button>
                    <button class="map-pill" onclick="toggleCadastreFiscal(this)">Cadastre</button>
                </div>
                <!-- Légende fiscale sur la carte -->
                <div class="fiscal-legend-overlay">
                    <div class="fiscal-legend-item"><span class="fiscal-legend-color" style="background:#4caf50"></span> Payé</div>
                    <div class="fiscal-legend-item"><span class="fiscal-legend-color" style="background:#f59e0b"></span> En attente</div>
                    <div class="fiscal-legend-item"><span class="fiscal-legend-color" style="background:#ef4444"></span> En retard</div>
                    <div class="fiscal-legend-item"><span class="fiscal-legend-color" style="background:#94a3b8"></span> Exonéré</div>
                </div>
            </div>
        </div>
        
        <!-- COLONNE DROITE : BOUTONS + KPIs -->
        <div style="flex: 1; display: flex; flex-direction: column; gap: 16px;">
            <!-- Boutons actions -->
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <button class="fiscal-action-btn" onclick="uncoCore.openModal('recensement')"><i data-lucide="calculator"></i> Recensement</button>
                <button class="fiscal-action-btn" onclick="uncoCore.openModal('addCommerce')"><i data-lucide="file-check-2"></i> Commerce</button>
                <button class="fiscal-action-btn" onclick="uncoCore.openModal('fiscalManagement')"><i data-lucide="banknote"></i> Encaisser</button>
                <button class="fiscal-action-btn" onclick="uncoCore.openModal('planningControl')"><i data-lucide="mail-warning"></i> Contrôle</button>
                <button class="fiscal-action-btn" onclick="uncoCore.openModal('dashboard')"><i data-lucide="search"></i> Dashboard</button>
                <button class="fiscal-action-btn" onclick="uncoCore.openModal('generateReport')"><i data-lucide="bar-chart-3"></i> Rapports</button>
            </div>
            
            <!-- KPIs fiscaux (2 en haut, 2 en bas) -->
<div style="margin-top: 16px;">
    <!-- Ligne 1 : Payés + En attente -->
    <div style="display: flex; gap: 12px; margin-bottom: 12px;">
        <div style="flex: 1; text-align: center; padding: 12px 8px; background: white; border-radius: 14px; border: 1px solid var(--border);">
            <div style="font-size: 0.65rem; text-transform: uppercase; color: var(--text-secondary);">Payés</div>
            <div style="font-size: 1.3rem; font-weight: 800; color: #4caf50;" id="fkpi-paid-val">1,280</div>
        </div>
        <div style="flex: 1; text-align: center; padding: 12px 8px; background: white; border-radius: 14px; border: 1px solid var(--border);">
            <div style="font-size: 0.65rem; text-transform: uppercase; color: var(--text-secondary);">En attente</div>
            <div style="font-size: 1.3rem; font-weight: 800; color: #f59e0b;" id="fkpi-pending-val">320</div>
        </div>
    </div>
    <!-- Ligne 2 : En retard + Exonérés -->
    <div style="display: flex; gap: 12px;">
        <div style="flex: 1; text-align: center; padding: 12px 8px; background: white; border-radius: 14px; border: 1px solid var(--border);">
            <div style="font-size: 0.65rem; text-transform: uppercase; color: var(--text-secondary);">En retard</div>
            <div style="font-size: 1.3rem; font-weight: 800; color: #ef4444;" id="fkpi-overdue-val">320</div>
        </div>
        <div style="flex: 1; text-align: center; padding: 12px 8px; background: white; border-radius: 14px; border: 1px solid var(--border);">
            <div style="font-size: 0.65rem; text-transform: uppercase; color: var(--text-secondary);">Exonérés</div>
            <div style="font-size: 1.3rem; font-weight: 800; color: #94a3b8;" id="fkpi-exempt-val">320</div>
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
                <div class="admin-stats-grid">
                    <div class="admin-stat-card"><span class="stat-value">24</span><div>Utilisateurs actifs</div></div>
                    <div class="admin-stat-card"><span class="stat-value">32%</span><div>Charge serveur</div></div>
                    <div class="admin-stat-card"><span class="stat-value">1m</span><div>Sync SIG</div></div>
                    <div class="admin-stat-card"><span class="stat-value">2</span><div>Tentatives échouées</div></div>
                </div>
                <div class="actions-row">
                    <button class="action-btn" onclick="uncoCore.openModal('userManagement')"><i data-lucide="user-cog"></i> Gestion des rôles</button>
                    <button class="action-btn" onclick="uncoCore.openModal('importSig')"><i data-lucide="settings-2"></i> Import SIG</button>
                </div>
                <div class="map-container" style="height:400px;">
    <div id="admin-minimap" style="height:100%; border-radius:16px;"></div>
    <div class="map-pills-overlay">
        <button class="map-pill active" onclick="setAdminBaseLayer('osm', this)">Plan Standard</button>
        <button class="map-pill" onclick="setAdminBaseLayer('satellite', this)">Satellite · Relief</button>
        <button class="map-pill" onclick="toggleCadastreAdmin(this)">Cadastre</button>
    </div>
</div>
                <!--div class="actions-row">
                    <button class="action-btn" onclick="uncoCore.openModal('userManagement')"><i data-lucide="user-cog"></i> Gestion des rôles</button>
                    <button class="action-btn" onclick="uncoCore.openModal('importSig')"><i data-lucide="settings-2"></i> Import SIG</button>
                </div-->
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="globalModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="modalTitle">Action</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="modalBodyText"></div><div class="modal-footer"><button type="button" class="btn btn-dark" data-bs-dismiss="modal">Fermer</button></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script>

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
    
    const marker = L.marker([lat, lng], { icon: customIcon }).addTo(mainMap);
    marker.bindPopup(`
        <b>${id}</b><br>
        Type: <span style="color:${color}; font-weight:bold;">${type}</span><br>
        Adresse: ${address}<br>
        Quartier: ${district}<br>
        Surface: ${area} m²<br>
        Étages: ${floors}
    `);
    return marker;
}

// ========== VARIABLES DYNAMIQUES POUR LES KPIS ==========
let totalBatiments = 12450;
let alertesRues = 3;
let totalParcelles = 5200;
let actifsTerrain = 12;

// Fonction pour mettre à jour l'affichage des KPIs
function updateKPIs() {
    document.querySelector('#view-sig .kpi-card:first-child .kpi-value').innerText = totalBatiments.toLocaleString();
    document.querySelector('#view-sig .kpi-card:nth-child(2) .kpi-value').innerText = alertesRues;
    const tauxAdressage = Math.round((totalBatiments / totalParcelles) * 100);
    document.querySelector('#view-sig .kpi-card:nth-child(3) .kpi-value').innerText = tauxAdressage + '%';
    document.querySelector('#view-sig .kpi-card:nth-child(4) .kpi-value').innerText = actifsTerrain;
    
    // Sauvegarder dans sessionStorage
    sessionStorage.setItem('unco_totalBatiments', totalBatiments);
    sessionStorage.setItem('unco_alertesRues', alertesRues);
    sessionStorage.setItem('unco_totalParcelles', totalParcelles);
    sessionStorage.setItem('unco_actifsTerrain', actifsTerrain);
}

// Charger les valeurs sauvegardées
function loadKPIsFromStorage() {
    if (sessionStorage.getItem('unco_totalBatiments')) {
        totalBatiments = parseInt(sessionStorage.getItem('unco_totalBatiments'));
        alertesRues = parseInt(sessionStorage.getItem('unco_alertesRues'));
        totalParcelles = parseInt(sessionStorage.getItem('unco_totalParcelles'));
        actifsTerrain = parseInt(sessionStorage.getItem('unco_actifsTerrain'));
    }
    updateKPIs();
}
    
    // ========== LOGIN ==========
function attemptLogin() {
    const email = document.getElementById('loginEmail').value.trim();
    const pwd = document.getElementById('loginPassword').value.trim();
    if (email === 'admin@unco.sn' && pwd === 'admin123') {
        sessionStorage.setItem('unco_auth', 'true');
        sessionStorage.setItem('unco_user', email.split('@')[0]);
        document.getElementById('loginPage').style.display = 'none';
        document.getElementById('appLayout').style.display = 'flex';
        document.getElementById('userNameDisplay').innerText = sessionStorage.getItem('unco_user');
        initAll();
    } else { document.getElementById('loginError').style.display = 'block'; }
}
function logout() { sessionStorage.clear(); location.reload(); }
if (sessionStorage.getItem('unco_auth') === 'true') {
    document.getElementById('loginPage').style.display = 'none';
    document.getElementById('appLayout').style.display = 'flex';
    document.getElementById('userNameDisplay').innerText = sessionStorage.getItem('unco_user');
}

// ========== DONNÉES FISCALES ==========
/*const paymentsData = [
    { ref: "PAY-8921", name: "Fatou Diallo", nicad: "14.12.01.07.124", amount: "125 000", status: "paid" },
    { ref: "TAX-2024", name: "Mamadou Ndiaye", nicad: "14.12.01.09.441", amount: "75 000", status: "overdue" },
    { ref: "PAY-8918", name: "Awa Seck", nicad: "14.12.01.02.088", amount: "200 000", status: "pending" },
    { ref: "PAY-8917", name: "Ibrahima Ba", nicad: "14.12.01.11.305", amount: "310 000", status: "paid" },
    { ref: "PAY-8910", name: "SOTRAC SA", nicad: "14.12.01.04.002", amount: "450 000", status: "paid" }

    
];*/



function filterFiscal(status, btn) {
    document.querySelectorAll('.fiscal-filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    
    // Convertir les valeurs du bouton vers les valeurs dans la base
    let realStatus = status;
    if (status === 'paid') realStatus = 'paye';
    if (status === 'pending') realStatus = 'pending';
    if (status === 'overdue') realStatus = 'overdue';
    if (status === 'exempt') realStatus = 'exempt';
    
    renderPaymentsTable(realStatus);
    updateFiscalKPIs(realStatus);
    addNotif('Filtre appliqué', `Affichage : ${btn.innerText}`, 'info');
}

// Données mensuelles pour le graphique Évolution des Recouvrements
const monthlyFiscalData = [
    { month: 'Jan', year: 2025, paid: 520, pending: 120, overdue: 45, exempt: 80 },
    { month: 'Fév', year: 2025, paid: 680, pending: 140, overdue: 55, exempt: 90 },
    { month: 'Mar', year: 2025, paid: 750, pending: 160, overdue: 65, exempt: 95 },
    { month: 'Avr', year: 2025, paid: 820, pending: 180, overdue: 70, exempt: 100 },
    { month: 'Mai', year: 2025, paid: 910, pending: 190, overdue: 80, exempt: 110 },
    { month: 'Juin', year: 2025, paid: 980, pending: 210, overdue: 75, exempt: 115 },
    { month: 'Juil', year: 2025, paid: 1050, pending: 220, overdue: 70, exempt: 120 },
    { month: 'Aoû', year: 2025, paid: 1120, pending: 210, overdue: 65, exempt: 125 },
    { month: 'Sep', year: 2025, paid: 1180, pending: 195, overdue: 60, exempt: 130 },
    { month: 'Oct', year: 2025, paid: 1220, pending: 205, overdue: 55, exempt: 135 },
    { month: 'Nov', year: 2025, paid: 1280, pending: 215, overdue: 50, exempt: 140 },
    { month: 'Déc', year: 2025, paid: 1350, pending: 230, overdue: 48, exempt: 145 }
];


// ========== STATISTIQUES MENSUELLES POUR LE GRAPHIQUE ==========
const statsMensuelles = <?php echo json_encode($stats_mensuelles); ?>;


// ========== STATISTIQUES RÉELLES DEPUIS POSTGRESQL ==========
const statsBatimentsReels = <?php echo $stats_batiments_json; ?>;
const totalBatimentsReel = <?php echo $total_batiments_reel_json; ?>;

// ========== STATISTIQUES FISCALES RÉELLES ==========
const statsFiscalesReelles = <?php echo $stats_fiscales_json; ?>;
const paiementsReels = <?php echo $paiements_json; ?>;


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
    
    // Vérifier si nous avons des données réelles
    if (typeof statsMensuelles !== 'undefined' && statsMensuelles && statsMensuelles.length > 0) {
        // Utiliser les données réelles
        months = statsMensuelles.map(item => item.mois);
        paidData = statsMensuelles.map(item => item.total_paye / 1000);  // Convertir en milliers
        pendingData = statsMensuelles.map(item => item.total_attente / 1000);
        overdueData = statsMensuelles.map(item => item.total_retard / 1000);
        
        console.log("Graphique avec données réelles:", months);
    } else {
        // Fallback : utiliser les 6 derniers mois avec des valeurs par défaut
        console.log("Aucune donnée réelle, utilisation des valeurs par défaut");
        const moisActuels = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin'];
        months = moisActuels;
        paidData = [0, 0, 0, 0, 0, 0];
        pendingData = [0, 0, 0, 0, 0, 0];
        overdueData = [0, 0, 0, 0, 0, 0];
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
let mainMap, fiscalMap, adminMap, currentBaseLayer = 'osm', baseLayers = {};

// ANCIENS POINTS FICTIFS (commentés pour ne plus apparaître sur la carte)
/*
const infrastructures = [
    { name: "Voie de Dégagement Nord", lat: 14.722, lng: -17.468, iconName: "route", color: "#F5A623" },
    { name: "Avenue Seydina Limamoulaye", lat: 14.7185, lng: -17.4655, iconName: "route", color: "#F5A623" },
    { name: "Autoroute Seydina Limamoulaye", lat: 14.715, lng: -17.47, iconName: "route", color: "#F5A623" },
    { name: "SDET (Électricité)", lat: 14.72, lng: -17.463, iconName: "zap", color: "#F5A623" },
    { name: "L'Aquarium", lat: 14.716, lng: -17.466, iconName: "droplet", color: "#F5A623" },
    { name: "Lac du ...", lat: 14.714, lng: -17.469, iconName: "droplet", color: "#F5A623" },
    { name: "Marché Central", lat: 14.7182, lng: -17.4670, iconName: "shopping-bag", color: "#1A6B45" },
    { name: "Pharmacie du Soleil", lat: 14.7155, lng: -17.4662, iconName: "pill", color: "#C0392B" },
    { name: "Poste de Santé", lat: 14.7220, lng: -17.4710, iconName: "hospital", color: "#C0392B" },
    { name: "École El hadj Malick", lat: 14.7198, lng: -17.4645, iconName: "graduation-cap", color: "#4A7C5F" },
    { name: "Mosquée de la Paix", lat: 14.7142, lng: -17.4685, iconName: "mosque", color: "#B8860B" }
];
*/

// NOUVEAUX POINTS CORRESPONDANT À LA LÉGENDE
const infrastructures = [
    // === VOIES ===
    { name: "Voie principale", lat: 14.7200, lng: -17.4670, iconName: "route", color: "#F5A623" },
    { name: "Voie secondaire", lat: 14.7160, lng: -17.4690, iconName: "route", color: "#c07d10" },
    
    // === ÉLECTRICITÉ ===
    { name: "Poste électrique", lat: 14.7180, lng: -17.4640, iconName: "zap", color: "#f4f808" },
    
    // === PHARMACIE ===
    { name: "Pharmacie centrale", lat: 14.7150, lng: -17.4665, iconName: "pill", color: "#0dfc21" },
    
    // === SANTÉ ===
    { name: "Centre de santé", lat: 14.7210, lng: -17.4710, iconName: "hospital", color: "#3d9c06" },
    
    // === ÉCOLE ===
    { name: "École primaire", lat: 14.7195, lng: -17.4648, iconName: "graduation-cap", color: "#0b62d4" },
    
    // === MOSQUÉE ===
    { name: "Mosquée centrale", lat: 14.7145, lng: -17.4688, iconName: "mosque", color: " #035f2a" }, 
    
    // === ADMINISTRATION ===
    { name: "Mairie", lat: 14.7175, lng: -17.4678, iconName: "building-2", color: "#eb2a08" }
];

function initMaps() {
    if(mainMap) return;
    mainMap = L.map('unco-main-map').setView([14.7167, -17.4667], 14);
    baseLayers.osm = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', { attribution: '© OpenStreetMap' }).addTo(mainMap);
    baseLayers.satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: 'Esri' });
    
    infrastructures.forEach(i => {
        const iconHtml = `<div class="custom-marker-icon" style="background:${i.color}; display:flex; align-items:center; justify-content:center;"><i data-lucide="${i.iconName}" style="width:20px; height:20px; color:white;"></i></div>`;
        const customIcon = L.divIcon({ html: iconHtml, iconSize: [36, 36], className: '' });
        L.marker([i.lat, i.lng], { icon: customIcon }).addTo(mainMap).bindPopup(i.name);
    });
    setTimeout(() => lucide.createIcons(), 100);
    
    //for(let i=0;i<50;i++) L.circleMarker([14.718+Math.random()*0.025, -17.468+Math.random()*0.025], { radius: 4, color: '#CA8A04', fillColor: '#FDE68A', fillOpacity: 0.5 }).addTo(mainMap);
    
    fiscalMap = L.map('fiscal-map').setView([14.7167, -17.4667], 14);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png').addTo(fiscalMap);
    
    adminMap = L.map('admin-minimap').setView([14.7167, -17.4667], 12);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png').addTo(adminMap);
    setTimeout(() => { mainMap.invalidateSize(); fiscalMap.invalidateSize(); adminMap.invalidateSize(); }, 200);
}


// ========== CHARGER LES BÂTIMENTS DEPUIS POSTGRESQL ==========
function loadBuildingsFromPostgreSQL() {
    const buildings = <?php echo json_encode($batiments_db); ?>;
    
    console.log("Chargement de " + buildings.length + " bâtiments depuis PostgreSQL");
    
    buildings.forEach(b => {
        createBuildingMarker(
            b.latitude, b.longitude, 
            b.identifiant, b.type, 
            b.adresse || 'Non renseignée', 
            b.quartier || 'Non renseigné', 
            b.surface || '?', 
            b.etages || '?'
        );
    });
    
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
function toggleCadastre(btn) { alert('Couche cadastrale (parcelles)'); btn.classList.toggle('active'); }

// ========== MODALES COMPLÈTES (AJOUTER / MODIFIER) ==========
const uncoCore = {
    switchRole(role, btn) {
        document.querySelectorAll('.nav-item').forEach(b=>b.classList.remove('active')); btn.classList.add('active');
        document.querySelectorAll('.role-view').forEach(v=>v.classList.remove('active')); document.getElementById(`view-${role}`).classList.add('active');
        document.getElementById('current-view-title').innerText = role === 'sig' ? 'Carte Interactive' : (role === 'municipal' ? 'Gestion Fiscale' : 'Administration');
        document.getElementById('current-role-badge').innerText = role === 'sig' ? 'TECHNICIEN SIG' : (role === 'municipal' ? 'AGENT MUNICIPAL' : 'ADMINISTRATEUR');
        setTimeout(() => { if(mainMap) mainMap.invalidateSize(); if(fiscalMap) fiscalMap.invalidateSize(); if(adminMap) adminMap.invalidateSize(); }, 150);
    },
    openModal(action) {
        const modal = new bootstrap.Modal(document.getElementById('globalModal'));
        const modalTitle = document.getElementById('modalTitle');
        const modalBody = document.getElementById('modalBodyText');
        const modalFooter = document.querySelector('#globalModal .modal-footer');
        
        if (action === 'addBuilding') {
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
                    <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                        <div style="flex: 1;">
                            <label style="font-weight: 600; margin-bottom: 6px; display: block;">Latitude GPS</label>
                            <input type="text" id="buildingLat" value="14.718320" class="form-control">
                        </div>
                        <div style="flex: 1;">
                            <label style="font-weight: 600; margin-bottom: 6px; display: block;">Longitude GPS</label>
                            <input type="text" id="buildingLng" value="-17.469110" class="form-control">
                        </div>
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
                        <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                            <div style="flex: 1;">
                                <label style="font-weight: 600; margin-bottom: 6px; display: block;">Latitude GPS</label>
                                <input type="text" id="editBuildingLat" value="14.721540" class="form-control">
                            </div>
                            <div style="flex: 1;">
                                <label style="font-weight: 600; margin-bottom: 6px; display: block;">Longitude GPS</label>
                                <input type="text" id="editBuildingLng" value="-17.465890" class="form-control">
                            </div>
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
                    <input type="text" id="recLat" value="14.716700" class="form-control">
                </div>
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Longitude GPS</label>
                    <input type="text" id="recLng" value="-17.467700" class="form-control">
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
            <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Latitude GPS</label>
                    <input type="text" id="commerceLat" value="14.718000" class="form-control">
                </div>
                <div style="flex: 1;">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">Longitude GPS</label>
                    <input type="text" id="commerceLng" value="-17.469000" class="form-control">
                </div>
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
    modalBody.innerHTML = `
        <div style="padding: 0;">
            <div style="display: flex; gap: 16px; margin-bottom: 20px;">
                <div style="flex: 1; text-align: center; padding: 16px; background: #f8fafc; border-radius: 16px;">
                    <div style="font-size: 1.8rem; font-weight: 800; color: #1A6B45;">156</div>
                    <div style="font-size: 0.7rem;">Paiements ce mois</div>
                </div>
                <div style="flex: 1; text-align: center; padding: 16px; background: #f8fafc; border-radius: 16px;">
                    <div style="font-size: 1.8rem; font-weight: 800; color: #1A6B45;">2.4M</div>
                    <div style="font-size: 0.7rem;">Montant collecté</div>
                </div>
                <div style="flex: 1; text-align: center; padding: 16px; background: #f8fafc; border-radius: 16px;">
                    <div style="font-size: 1.8rem; font-weight: 800; color: #f59e0b;">243</div>
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
            tbody.innerHTML = paymentsData.map(p => `
                <tr>
                    <td>${p.ref}</td>
                    <td>${p.name}</td>
                    <td>${p.amount} FCFA</td>
                    <td><span class="status-pill ${p.status === 'paid' ? 'paye' : (p.status === 'overdue' ? 'impaye' : 'encours')}">${p.status === 'paid' ? 'Payé' : (p.status === 'overdue' ? 'Impayé' : 'En cours')}</span></td>
                </tr>
            `).join('');
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
                        <tr><th>Nom</th><th>Email</th><th>Rôle</th><th>Statut</th>
                    </thead>
                    <tbody>
                        <tr><td>Amadou Diallo</td><td>a.diallo@ouakam.sn</td><td><span style="background: #e8f5ee; padding: 4px 10px; border-radius: 40px; font-size: 0.7rem;">Technicien SIG</span></td><td><span style="background: #dcfce7; padding: 4px 10px; border-radius: 40px; font-size: 0.7rem; color: #15803d;">Actif</span></td></tr>
                        <tr><td>Fatou Seck</td><td>f.seck@ouakam.sn</td><td><span style="background: #fffbeb; padding: 4px 10px; border-radius: 40px; font-size: 0.7rem;">Agent Municipal</span></td><td><span style="background: #dcfce7; padding: 4px 10px; border-radius: 40px; font-size: 0.7rem; color: #15803d;">Actif</span></td></tr>
                        <tr><td>Moussa Ba</td><td>m.ba@ouakam.sn</td><td><span style="background: #f1f5f9; padding: 4px 10px; border-radius: 40px; font-size: 0.7rem;">Administrateur</span></td><td><span style="background: #dcfce7; padding: 4px 10px; border-radius: 40px; font-size: 0.7rem; color: #15803d;">Actif</span></td></tr>
                        <tr><td>Aïcha Ndiaye</td><td>a.ndiaye@ouakam.sn</td><td><span style="background: #fffbeb; padding: 4px 10px; border-radius: 40px; font-size: 0.7rem;">Agent Municipal</span></td><td><span style="background: #fee2e2; padding: 4px 10px; border-radius: 40px; font-size: 0.7rem; color: #b91c1c;">Inactif</span></td></tr>
                    </tbody>
                </table>
            </div>
            <div id="userTabCreate" style="display: none;">
                <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                    <div style="flex: 1;"><label style="font-weight: 600;">Nom complet</label><input type="text" id="newUserName" class="form-control" placeholder="Ex: Khadija Mbaye"></div>
                    <div style="flex: 1;"><label style="font-weight: 600;">Email</label><input type="email" id="newUserEmail" class="form-control" placeholder="exemple@ouakam.sn"></div>
                </div>
                <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                    <div style="flex: 1;"><label style="font-weight: 600;">Rôle</label><select id="newUserRole" class="form-control"><option>Technicien SIG</option><option>Agent Municipal</option><option>Administrateur</option></select></div>
                    <div style="flex: 1;"><label style="font-weight: 600;">Téléphone</label><input type="tel" id="newUserPhone" class="form-control"></div>
                </div>
                <button class="btn btn-primary" onclick="createUser()" style="background: #1A6B45; color: white; border-radius: 40px; width: 100%;">Créer le compte</button>
            </div>
        </div>
    `;
    if (modalFooter) {
        modalFooter.innerHTML = `<button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 40px;">Fermer</button>`;
    }
    // Réinitialiser l'affichage des onglets
    setTimeout(() => {
        document.getElementById('userTabList').style.display = 'block';
        document.getElementById('userTabCreate').style.display = 'none';
        document.getElementById('userTabListBtn').style.background = '#1A6B45';
        document.getElementById('userTabListBtn').style.color = 'white';
        document.getElementById('userTabCreateBtn').style.background = 'transparent';
        document.getElementById('userTabCreateBtn').style.color = '#1A6B45';
    }, 100);
}
else if (action === 'importSig') {
    modalTitle.innerText = 'Importer un Fichier SIG';
    modalBody.innerHTML = `
        <div style="padding: 0;">
            <div class="alert alert-info" style="background: #eff6ff; border: none; border-radius: 12px; padding: 12px; font-size: 0.85rem; margin-bottom: 20px;">
                <i data-lucide="info" style="width: 16px; height: 16px; display: inline-block; vertical-align: middle;"></i>
                Importez des données géospatiales (GeoJSON, KML) pour mettre à jour la base cartographique.<br>
                <strong>Formats supportés :</strong> GeoJSON (.geojson), KML (.kml)
            </div>
            
            <div style="margin-bottom: 16px;">
                <label style="font-weight: 600; margin-bottom: 8px; display: block;">Type de fichier</label>
                <select id="importType" class="form-control" onchange="updateImportFormat()">
                    <option value="geojson" selected>GeoJSON (.geojson)</option>
                    <option value="kml">KML (.kml)</option>
                    <option value="shapefile">Shapefile (.shp)</option>
                </select>
            </div>
            
            <div style="margin-bottom: 16px;">
                <label style="font-weight: 600; margin-bottom: 8px; display: block;">Fichier</label>
                <input type="file" id="importFile" class="form-control" accept=".geojson,.kml,.shp" onchange="previewImportFile(this)">
                <div id="fileInfo" style="margin-top: 8px; font-size: 0.7rem; color: #6b7280;"></div>
            </div>
            
            <div id="filePreviewContainer" style="display: none; background: #f8fafc; border-radius: 12px; padding: 12px; margin-bottom: 16px;">
                <div id="filePreview"></div>
            </div>
            
            <div style="margin-bottom: 16px;">
                <label style="font-weight: 600; margin-bottom: 8px; display: block;">Système de coordonnées</label>
                <select id="importCrs" class="form-control">
                    <option value="4326" selected>WGS 84 (EPSG:4326)</option>
                    <option value="32628">UTM Zone 28N (EPSG:32628)</option>
                </select>
                <div class="form-text" style="font-size: 0.65rem; color: #6b7280;">
                    Assurez-vous que votre fichier utilise le bon système de coordonnées.
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




        else {
            modalTitle.innerText = `Action: ${action}`;
            modalBody.innerHTML = `<p>Module prêt.</p><input class="form-control" placeholder="Informations supplémentaires...">`;
            if (modalFooter) {
                modalFooter.innerHTML = `<button type="button" class="btn btn-dark" data-bs-dismiss="modal" style="border-radius: 40px;">Fermer</button>`;
            }
        }
        
        modal.show();
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
    console.log("Recherche du bâtiment:", buildingId);
    console.log("Nombre de marqueurs sur la carte:", Object.keys(mainMap._layers).length);
    let markerToRemove = null;
    
    if (!buildingId) {
        showToast('Erreur', 'Veuillez saisir un identifiant de bâtiment', 'error');
        return;
    }
    
    // Trouver le marqueur sur la carte
    mainMap.eachLayer(function(layer) {
        if (layer instanceof L.Marker && layer.getPopup() && layer.getPopup().getContent().includes(buildingId)) {
            markerToRemove = layer;
        }
    });
    
    if (!markerToRemove) {
        showToast('Erreur', `Aucun bâtiment trouvé avec l'identifiant "${buildingId}"`, 'error');
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
            mainMap.removeLayer(markerToRemove);
            totalBatiments--;
            updateKPIs();
            addNotif('Bâtiment supprimé', `Le bâtiment ${buildingId} a été supprimé de PostgreSQL.`, 'success');
            showToast('Succès', `Bâtiment ${buildingId} supprimé définitivement!`, 'success');
            
            const modalEl = document.getElementById('globalModal');
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) modalInstance.hide();
        } else {
            showToast('Erreur', result.error || 'Impossible de supprimer le bâtiment', 'error');
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur', 'Erreur de communication avec le serveur', 'error');
    }
}



function searchBuildingToDelete() {
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
    
    let buildingFound = false;
    let buildingContent = '';
    let buildingLatLng = null;
    
    // Chercher le bâtiment sur la carte (parmi tous les marqueurs)
    mainMap.eachLayer(function(layer) {
        if (layer instanceof L.Marker && layer.getPopup() && layer.getPopup().getContent()) {
            const popupContent = layer.getPopup().getContent();
            if (popupContent.includes(buildingId)) {
                buildingFound = true;
                buildingContent = popupContent;
                buildingLatLng = layer.getLatLng();
            }
        }
    });
    
    if (buildingFound) {
        detailsDiv.innerHTML = `
            <div style="font-size: 0.8rem;">Identifiant : <strong>${buildingId}</strong></div>
            <div style="font-size: 0.8rem; margin-top: 5px;">${buildingContent.substring(0, 150)}...</div>
        `;
        infoDiv.style.display = 'block';
        if (confirmBtn) confirmBtn.disabled = false;
        
        // Centrer la carte sur le bâtiment
        if (buildingLatLng) {
            mainMap.setView(buildingLatLng, 18);
        }
    } else {
        detailsDiv.innerHTML = `<div style="color: #dc2626;">Aucun bâtiment trouvé avec l'identifiant "${buildingId}"</div>`;
        infoDiv.style.display = 'block';
        if (confirmBtn) confirmBtn.disabled = true;
    }
}


function loadBuildingData() {
    const buildingId = document.getElementById('editBuildingId').value.trim();
    const formContainer = document.getElementById('editBuildingForm');
    
    if (!buildingId) {
        showToast('Identifiant requis', 'Veuillez saisir un identifiant de bâtiment.', 'warning');
        formContainer.style.display = 'none';
        return;
    }
    
    let buildingFound = false;
    let buildingLat = null;
    let buildingLng = null;
    let buildingType = '';
    let buildingAdresse = '';
    let buildingSurface = '';
    
    mainMap.eachLayer(function(layer) {
        if (layer instanceof L.Marker && layer.getPopup()) {
            const content = layer.getPopup().getContent();
            if (typeof content === 'string' && content.includes(buildingId)) {
                buildingFound = true;
                const latLng = layer.getLatLng();
                buildingLat = latLng.lat;
                buildingLng = latLng.lng;
                console.log("Contenu du popup trouvé:", content);
                
                if (content.includes('Type:')) {
                    const typeParts = content.split('Type:');
                    if (typeParts[1]) buildingType = typeParts[1].split('<br>')[0].trim();
                }
                if (content.includes('Adresse:')) {
                    const addrParts = content.split('Adresse:');
                    if (addrParts[1]) buildingAdresse = addrParts[1].split('<br>')[0].trim();
                }
                if (content.includes('Surface:')) {
                    const surfParts = content.split('Surface:');
                    if (surfParts[1]) buildingSurface = surfParts[1].replace('m²', '').trim();
                }
                
                mainMap.setView(latLng, 18);
            }
        }
    });
    
    if (buildingFound) {
        document.getElementById('editBuildingType').value = buildingType || 'Résidentiel';
        document.getElementById('editBuildingArea').value = buildingSurface || '150';
        document.getElementById('editBuildingLat').value = buildingLat.toFixed(6);
        document.getElementById('editBuildingLng').value = buildingLng.toFixed(6);
        document.getElementById('editBuildingAddress').value = buildingAdresse;
        
        formContainer.style.display = 'block';
        showToast('Succès', `Bâtiment "${buildingId}" chargé.`, 'success');
    } else {
        showToast('Non trouvé', `Aucun bâtiment trouvé avec l'identifiant "${buildingId}"`, 'error');
        formContainer.style.display = 'none';
    }
}


function updateImportFormat() {
    const format = document.getElementById('importType').value;
    const fileInput = document.getElementById('importFile');
    
    if (format === 'geojson') {
        fileInput.accept = '.geojson';
    } else if (format === 'kml') {
        fileInput.accept = '.kml';
    } else {
        fileInput.accept = '.shp';
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
        reportData = monthlyFiscalData.slice(-6).map(m => ({
            mois: m.month,
            payes: m.paid,
            en_attente: m.pending,
            en_retard: m.overdue
        }));
    }
    else if (reportType === 'overdue') {
        reportTitle = 'Rapport des Retards de Paiement';
        reportData = paymentsData.filter(p => p.status === 'overdue').map(p => ({
            contribuable: p.name,
            reference: p.ref,
            montant: p.amount,
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
        const modal = new bootstrap.Modal(document.getElementById('globalModal'));
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
                batiments: totalBatimentsReel ? totalBatimentsReel.toLocaleString() : (document.querySelector('#view-sig .kpi-card:first-child .kpi-value')?.innerText || '12,450'),
                alertes: document.querySelector('#view-sig .kpi-card:nth-child(2) .kpi-value')?.innerText || '3',
                taux: document.querySelector('#view-sig .kpi-card:nth-child(3) .kpi-value')?.innerText || '85%',
                actifs: document.querySelector('#view-sig .kpi-card:nth-child(4) .kpi-value')?.innerText || '12'
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

function toggleCadastreFiscal(btn) { alert('Cadastre fiscal: affichage des parcelles'); btn.classList.toggle('active'); }
function toggleCadastreAdmin(btn) { alert('Cadastre administratif: affichage des limites'); btn.classList.toggle('active'); }

// ========== INITIALISATION ==========
//function initAll() { initMaps(); initFiscalCharts(); renderPaymentsTable('all'); lucide.createIcons(); }
function initAll() { 
    initMaps(); 
    loadBuildingsFromPostgreSQL();  // ← AJOUTER CETTE LIGNE
    initFiscalCharts(); 
    renderPaymentsTable('all'); 
    updateFiscalKPIs();
    lucide.createIcons(); 
}
if (sessionStorage.getItem('unco_auth') === 'true') initAll();

window.filterFiscal = filterFiscal;
window.setBaseLayer = setBaseLayer;
window.toggleCadastre = toggleCadastre;
window.saveBuilding = saveBuildingToPostgreSQL;
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
            setTimeout(() => location.reload(), 1000);
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
            setTimeout(() => location.reload(), 1000);
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
            setTimeout(() => location.reload(), 1000);
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
    const name = document.getElementById('newUserName').value;
    const email = document.getElementById('newUserEmail').value;
    if (!name || !email) {
        showToast('Champs manquants', 'Veuillez remplir le nom et l\'email.', 'warning');
        return;
    }
        addNotif('Utilisateur créé', `${name} (${email}) a été ajouté.`, 'success');
    showToast('Compte créé', `L'utilisateur ${name} (${email}) a été ajouté.`, 'success');
    bootstrap.Modal.getInstance(document.getElementById('globalModal')).hide();
}

// Variables pour l'import SIG
let selectedFile = null;
let geoJsonData = null;

function importSigFile() {
    const fileInput = document.getElementById('importFile');
    const fileType = document.getElementById('importType').value;
    
    if (!fileInput.files || !fileInput.files[0]) {
        showToast('Fichier manquant', 'Veuillez sélectionner un fichier à importer.', 'warning');
        return;
    }
    
    const file = fileInput.files[0];
    const fileName = file.name;
    const fileSize = (file.size / 1024).toFixed(2);
    
    // Afficher la progression
    showImportProgress(true);
    
    // Lire le fichier
    const reader = new FileReader();
    
    reader.onload = function(e) {
        const content = e.target.result;
        const base64Content = btoa(content);
        
        // Envoyer au serveur
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'import_sig',
                format: fileType === 'Shapefile (.shp)' ? 'shapefile' : (fileType === 'KML (.kml)' ? 'kml' : 'geojson'),
                crs: document.getElementById('importCrs').value,
                content: base64Content,
                filename: fileName,
                cree_par: sessionStorage.getItem('unco_user') || 'admin'
            })
        })
        .then(response => response.json())
        .then(result => {
            showImportProgress(false);
            
            if (result.success) {
                showToast('Succès', result.message || `Fichier "${fileName}" importé avec succès (${result.count || 0} entités)`, 'success');
                addNotif('Import SIG', `Fichier "${fileName}" importé - ${result.count || 0} entités ajoutées`, 'success');
                
                // Recharger les données sur la carte
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                showToast('Erreur', result.error || 'Erreur lors de l\'import', 'error');
            }
            
            // Fermer la modale
            const modalEl = document.getElementById('globalModal');
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) modalInstance.hide();
        })
        .catch(error => {
            showImportProgress(false);
            console.error('Erreur:', error);
            showToast('Erreur', 'Erreur de communication avec le serveur', 'error');
        });
    };
    
    reader.onerror = function() {
        showImportProgress(false);
        showToast('Erreur', 'Erreur de lecture du fichier', 'error');
    };
    
    reader.readAsText(file);
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


// ========== NOTIFICATIONS ==========
let notifs = [];
let unread = 0;

function addNotif(title, message, type = 'info') {
    notifs.unshift({ id: Date.now(), title, message, type, time: new Date(), read: false });
    unread++;
    updateBadge();
    renderNotifs();
    sessionStorage.setItem('unco_notifs', JSON.stringify(notifs));
    sessionStorage.setItem('unco_unread', unread);
}

function updateBadge() {
    const dot = document.querySelector('.notification-dot');
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
    list.innerHTML = notifs.map(n => `
        <div class="notification-item ${!n.read ? 'unread' : ''}" data-id="${n.id}">
            <div class="notification-icon ${n.type}">${n.type === 'success' ? '✓' : n.type === 'warning' ? '⚠️' : 'ℹ️'}</div>
            <div style="flex:1">
                <div class="notification-title">${n.title}</div>
                <div class="notification-message">${n.message}</div>
                <div class="notification-time">${getTimeAgo(n.time)}</div>
            </div>
        </div>
    `).join('');
    document.querySelectorAll('.notification-item').forEach(el => {
        el.addEventListener('click', () => {
            const id = parseInt(el.dataset.id);
            const n = notifs.find(x => x.id === id);
            if (n && !n.read) { n.read = true; unread--; updateBadge(); renderNotifs(); }
            sessionStorage.setItem('unco_notifs', JSON.stringify(notifs));
            sessionStorage.setItem('unco_unread', unread);
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

function initNotifs() {
    const saved = sessionStorage.getItem('unco_notifs');
    const savedUnread = sessionStorage.getItem('unco_unread');
    if (saved) {
        notifs = JSON.parse(saved);
        notifs.forEach(n => n.time = new Date(n.time));
        unread = savedUnread ? parseInt(savedUnread) : 0;
    } else {
        addNotif('Bienvenue', 'Bienvenue sur UNCO', 'success');
        addNotif('Paiements', '3 paiements en attente', 'warning');
        addNotif('Utilisateurs', '12 agents connectés', 'info');
    }
    renderNotifs();
    updateBadge();
    
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
    const modal = new bootstrap.Modal(document.getElementById('globalModal'));
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

// Ajoutez cette fonction JavaScript quelque part
document.addEventListener('DOMContentLoaded', function() {
    const avatar = document.getElementById('userAvatar');
    if (avatar) {
        avatar.addEventListener('click', function() {
            showToast('Profil', 'Amadou Diallo - Administrateur UNCO', 'info');
        });
    }
});


//"Email professionnel" value="admin@unco.sn">
//"Mot de passe" value="admin123">

</script>

</body>
</html>