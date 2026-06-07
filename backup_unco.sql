--
-- PostgreSQL database dump
--

-- Dumped from database version 15.3
-- Dumped by pg_dump version 15.3

-- Started on 2026-06-04 23:52:20

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

DROP DATABASE unco_db;
--
-- TOC entry 4370 (class 1262 OID 30202)
-- Name: unco_db; Type: DATABASE; Schema: -; Owner: postgres
--

CREATE DATABASE unco_db WITH TEMPLATE = template0 ENCODING = 'UTF8' LOCALE_PROVIDER = libc LOCALE = 'French_France.1252';


ALTER DATABASE unco_db OWNER TO postgres;

\connect unco_db

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- TOC entry 2 (class 3079 OID 30203)
-- Name: postgis; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS postgis WITH SCHEMA public;


--
-- TOC entry 4371 (class 0 OID 0)
-- Dependencies: 2
-- Name: EXTENSION postgis; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION postgis IS 'PostGIS geometry and geography spatial types and functions';


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- TOC entry 223 (class 1259 OID 31302)
-- Name: batiments; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.batiments (
    id integer NOT NULL,
    identifiant character varying(50) NOT NULL,
    type character varying(50),
    adresse text,
    quartier character varying(100),
    latitude numeric(10,6),
    longitude numeric(10,6),
    surface integer,
    etages character varying(20),
    observations text,
    date_creation timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    date_modification timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    cree_par character varying(100)
);


ALTER TABLE public.batiments OWNER TO postgres;

--
-- TOC entry 222 (class 1259 OID 31301)
-- Name: batiments_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.batiments_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.batiments_id_seq OWNER TO postgres;

--
-- TOC entry 4372 (class 0 OID 0)
-- Dependencies: 222
-- Name: batiments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.batiments_id_seq OWNED BY public.batiments.id;


--
-- TOC entry 235 (class 1259 OID 31366)
-- Name: commerces; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.commerces (
    id integer NOT NULL,
    nom character varying(200) NOT NULL,
    type character varying(100),
    adresse text,
    latitude double precision,
    longitude double precision,
    proprietaire character varying(200),
    telephone character varying(50),
    observations text,
    date_creation timestamp without time zone DEFAULT now(),
    statut character varying(50) DEFAULT 'actif'::character varying,
    cree_par character varying(100)
);


ALTER TABLE public.commerces OWNER TO postgres;

--
-- TOC entry 234 (class 1259 OID 31365)
-- Name: commerces_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.commerces_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.commerces_id_seq OWNER TO postgres;

--
-- TOC entry 4373 (class 0 OID 0)
-- Dependencies: 234
-- Name: commerces_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.commerces_id_seq OWNED BY public.commerces.id;


--
-- TOC entry 221 (class 1259 OID 31291)
-- Name: compteurs; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.compteurs (
    id integer NOT NULL,
    nom character varying(50) NOT NULL,
    valeur integer DEFAULT 0,
    date_mise_a_jour timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.compteurs OWNER TO postgres;

--
-- TOC entry 220 (class 1259 OID 31290)
-- Name: compteurs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.compteurs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.compteurs_id_seq OWNER TO postgres;

--
-- TOC entry 4374 (class 0 OID 0)
-- Dependencies: 220
-- Name: compteurs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.compteurs_id_seq OWNED BY public.compteurs.id;


--
-- TOC entry 237 (class 1259 OID 31377)
-- Name: controles; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.controles (
    id integer NOT NULL,
    numero_parcelle character varying(100),
    type_controle character varying(100),
    zone_reglementaire character varying(100),
    observations text,
    statut character varying(50) DEFAULT 'conforme'::character varying,
    date_controle timestamp without time zone DEFAULT now(),
    controle_par character varying(100)
);


ALTER TABLE public.controles OWNER TO postgres;

--
-- TOC entry 236 (class 1259 OID 31376)
-- Name: controles_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.controles_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.controles_id_seq OWNER TO postgres;

--
-- TOC entry 4375 (class 0 OID 0)
-- Dependencies: 236
-- Name: controles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.controles_id_seq OWNED BY public.controles.id;


--
-- TOC entry 225 (class 1259 OID 31315)
-- Name: infrastructures; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.infrastructures (
    id integer NOT NULL,
    nom character varying(100) NOT NULL,
    categorie character varying(50),
    latitude numeric(10,6),
    longitude numeric(10,6),
    icone character varying(50),
    couleur character varying(20),
    date_creation timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.infrastructures OWNER TO postgres;

--
-- TOC entry 224 (class 1259 OID 31314)
-- Name: infrastructures_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.infrastructures_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.infrastructures_id_seq OWNER TO postgres;

--
-- TOC entry 4376 (class 0 OID 0)
-- Dependencies: 224
-- Name: infrastructures_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.infrastructures_id_seq OWNED BY public.infrastructures.id;


--
-- TOC entry 227 (class 1259 OID 31323)
-- Name: paiements; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.paiements (
    id integer NOT NULL,
    reference character varying(50) NOT NULL,
    contribuable character varying(100),
    nicad character varying(50),
    montant numeric(12,0),
    statut character varying(20),
    mode_paiement character varying(50),
    date_paiement timestamp without time zone,
    observations text,
    date_creation timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    numero_recu character varying(100),
    cree_par character varying(100)
);


ALTER TABLE public.paiements OWNER TO postgres;

--
-- TOC entry 226 (class 1259 OID 31322)
-- Name: paiements_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.paiements_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.paiements_id_seq OWNER TO postgres;

--
-- TOC entry 4377 (class 0 OID 0)
-- Dependencies: 226
-- Name: paiements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.paiements_id_seq OWNED BY public.paiements.id;


--
-- TOC entry 231 (class 1259 OID 31346)
-- Name: rapports; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.rapports (
    id integer NOT NULL,
    titre character varying(200),
    type_rapport character varying(50),
    periode character varying(50),
    format character varying(20),
    contenu text,
    fichier_url text,
    genere_par character varying(100),
    date_generation timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.rapports OWNER TO postgres;

--
-- TOC entry 230 (class 1259 OID 31345)
-- Name: rapports_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.rapports_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.rapports_id_seq OWNER TO postgres;

--
-- TOC entry 4378 (class 0 OID 0)
-- Dependencies: 230
-- Name: rapports_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.rapports_id_seq OWNED BY public.rapports.id;


--
-- TOC entry 233 (class 1259 OID 31356)
-- Name: recensements; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.recensements (
    id integer NOT NULL,
    nom_structure character varying(200) NOT NULL,
    type_structure character varying(100),
    latitude double precision,
    longitude double precision,
    adresse text,
    proprietaire character varying(200),
    telephone character varying(50),
    observations text,
    date_creation timestamp without time zone DEFAULT now(),
    cree_par character varying(100)
);


ALTER TABLE public.recensements OWNER TO postgres;

--
-- TOC entry 232 (class 1259 OID 31355)
-- Name: recensements_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.recensements_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.recensements_id_seq OWNER TO postgres;

--
-- TOC entry 4379 (class 0 OID 0)
-- Dependencies: 232
-- Name: recensements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.recensements_id_seq OWNED BY public.recensements.id;


--
-- TOC entry 239 (class 1259 OID 31389)
-- Name: rues; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.rues (
    id integer NOT NULL,
    nom character varying(200),
    longueur double precision,
    date_creation timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.rues OWNER TO postgres;

--
-- TOC entry 238 (class 1259 OID 31388)
-- Name: rues_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.rues_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.rues_id_seq OWNER TO postgres;

--
-- TOC entry 4380 (class 0 OID 0)
-- Dependencies: 238
-- Name: rues_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.rues_id_seq OWNED BY public.rues.id;


--
-- TOC entry 229 (class 1259 OID 31335)
-- Name: utilisateurs; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.utilisateurs (
    id integer NOT NULL,
    nom character varying(100),
    email character varying(100) NOT NULL,
    mot_de_passe character varying(255),
    role character varying(20),
    actif boolean DEFAULT true,
    date_creation timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    derniere_connexion timestamp without time zone
);


ALTER TABLE public.utilisateurs OWNER TO postgres;

--
-- TOC entry 228 (class 1259 OID 31334)
-- Name: utilisateurs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.utilisateurs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.utilisateurs_id_seq OWNER TO postgres;

--
-- TOC entry 4381 (class 0 OID 0)
-- Dependencies: 228
-- Name: utilisateurs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.utilisateurs_id_seq OWNED BY public.utilisateurs.id;


--
-- TOC entry 4145 (class 2604 OID 31305)
-- Name: batiments id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.batiments ALTER COLUMN id SET DEFAULT nextval('public.batiments_id_seq'::regclass);


--
-- TOC entry 4159 (class 2604 OID 31369)
-- Name: commerces id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.commerces ALTER COLUMN id SET DEFAULT nextval('public.commerces_id_seq'::regclass);


--
-- TOC entry 4142 (class 2604 OID 31294)
-- Name: compteurs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.compteurs ALTER COLUMN id SET DEFAULT nextval('public.compteurs_id_seq'::regclass);


--
-- TOC entry 4162 (class 2604 OID 31380)
-- Name: controles id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.controles ALTER COLUMN id SET DEFAULT nextval('public.controles_id_seq'::regclass);


--
-- TOC entry 4148 (class 2604 OID 31318)
-- Name: infrastructures id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.infrastructures ALTER COLUMN id SET DEFAULT nextval('public.infrastructures_id_seq'::regclass);


--
-- TOC entry 4150 (class 2604 OID 31326)
-- Name: paiements id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.paiements ALTER COLUMN id SET DEFAULT nextval('public.paiements_id_seq'::regclass);


--
-- TOC entry 4155 (class 2604 OID 31349)
-- Name: rapports id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.rapports ALTER COLUMN id SET DEFAULT nextval('public.rapports_id_seq'::regclass);


--
-- TOC entry 4157 (class 2604 OID 31359)
-- Name: recensements id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recensements ALTER COLUMN id SET DEFAULT nextval('public.recensements_id_seq'::regclass);


--
-- TOC entry 4165 (class 2604 OID 31392)
-- Name: rues id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.rues ALTER COLUMN id SET DEFAULT nextval('public.rues_id_seq'::regclass);


--
-- TOC entry 4152 (class 2604 OID 31338)
-- Name: utilisateurs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.utilisateurs ALTER COLUMN id SET DEFAULT nextval('public.utilisateurs_id_seq'::regclass);


--
-- TOC entry 4348 (class 0 OID 31302)
-- Dependencies: 223
-- Data for Name: batiments; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.batiments (id, identifiant, type, adresse, quartier, latitude, longitude, surface, etages, observations, date_creation, date_modification, cree_par) VALUES (9, 'BAT-OUA-2026-016', 'Résidentiel', 'Rue 12, Ouakam Nord', 'Ouakam Nord', 14.718520, -17.469310, 225, 'RDC', 'Ajouté le 06/05/2026', '2026-05-06 00:39:42.378607', '2026-05-06 00:39:42.378607', 'admin');
INSERT INTO public.batiments (id, identifiant, type, adresse, quartier, latitude, longitude, surface, etages, observations, date_creation, date_modification, cree_par) VALUES (10, 'BAT-OUA-2026-017', 'Résidentiel', 'Rue 12, Ouakam Nord', 'Ouakam Nord', 14.718100, -17.469710, 255, 'R+1', 'Ajouté le 06/05/2026', '2026-05-06 00:57:05.107358', '2026-05-06 00:57:05.107358', 'admin');
INSERT INTO public.batiments (id, identifiant, type, adresse, quartier, latitude, longitude, surface, etages, observations, date_creation, date_modification, cree_par) VALUES (12, 'BAT-OUA-2026-019', 'Résidentiel', 'Rue 12, Ouakam Nord', 'Ouakam Nord', 14.712320, -17.465110, 280, 'R+1', 'Ajouté le 06/05/2026', '2026-05-06 01:30:59.100278', '2026-05-06 01:30:59.100278', 'admin');
INSERT INTO public.batiments (id, identifiant, type, adresse, quartier, latitude, longitude, surface, etages, observations, date_creation, date_modification, cree_par) VALUES (13, 'BAT-OUA-2026-020', 'Résidentiel', 'Rue 12, Ouakam Nord', 'Ouakam Nord', 14.718620, -17.469410, 150, 'R+2', 'Ajouté le 06/05/2026', '2026-05-06 01:35:45.341004', '2026-05-06 01:35:45.341004', 'admin');
INSERT INTO public.batiments (id, identifiant, type, adresse, quartier, latitude, longitude, surface, etages, observations, date_creation, date_modification, cree_par) VALUES (14, 'BAT-OUA-2026-021', 'Résidentiel', 'Rue 12, Ouakam Nord', 'Ouakam Nord', 14.718320, -17.469110, 280, 'R+1', 'Ajouté le 06/05/2026', '2026-05-06 01:37:59.339618', '2026-05-06 01:37:59.339618', 'admin');
INSERT INTO public.batiments (id, identifiant, type, adresse, quartier, latitude, longitude, surface, etages, observations, date_creation, date_modification, cree_par) VALUES (15, 'BAT-OUA-2026-022', 'Résidentiel', 'Rue 12, Ouakam Nord', 'Ouakam Nord', 14.718329, -17.469118, 200, 'R+1', 'Ajouté le 06/05/2026', '2026-05-06 01:40:46.410905', '2026-05-06 01:48:35.481486', 'admin');
INSERT INTO public.batiments (id, identifiant, type, adresse, quartier, latitude, longitude, surface, etages, observations, date_creation, date_modification, cree_par) VALUES (16, 'BAT-OUA-2026-024', 'Commercial', 'Rue 12, Ouakam Nord', 'Ouakam Nord', 14.718320, -17.469110, 280, 'R+3', 'Ajouté le 06/05/2026', '2026-05-06 02:03:24.678371', '2026-05-06 02:03:24.678371', 'admin');
INSERT INTO public.batiments (id, identifiant, type, adresse, quartier, latitude, longitude, surface, etages, observations, date_creation, date_modification, cree_par) VALUES (17, 'BAT-OUA-2026-026', 'Commercial', 'Rue 12, Ouakam Nord', 'Ouakam Nord', 14.718720, -17.469410, 400, 'R+3', 'Ajouté le 06/05/2026', '2026-05-06 02:10:00.000683', '2026-05-06 02:10:00.000683', 'admin');
INSERT INTO public.batiments (id, identifiant, type, adresse, quartier, latitude, longitude, surface, etages, observations, date_creation, date_modification, cree_par) VALUES (18, 'BAT-OUA-2026-027', 'Mixte', 'Rue 12, Ouakam Nord', 'Ouakam Nord', 14.711320, -17.461110, 280, 'RDC', 'Ajouté le 06/05/2026', '2026-05-06 02:16:06.580039', '2026-05-06 02:16:06.580039', 'admin');
INSERT INTO public.batiments (id, identifiant, type, adresse, quartier, latitude, longitude, surface, etages, observations, date_creation, date_modification, cree_par) VALUES (19, 'BAT-OUA-2026-028', 'Mixte', 'Rue 12, Ouakam Nord', 'Ouakam Nord', 14.758320, -17.459110, 280, 'R+1', 'Ajouté le 06/05/2026', '2026-05-06 02:23:23.537734', '2026-05-06 02:23:23.537734', 'admin');
INSERT INTO public.batiments (id, identifiant, type, adresse, quartier, latitude, longitude, surface, etages, observations, date_creation, date_modification, cree_par) VALUES (20, 'BAT-OUA-2026-030', 'Équipement public', 'Rue 12, Ouakam Nord', 'Ouakam Nord', 14.728320, -17.459110, 280, 'RDC', 'Ajouté le 06/05/2026', '2026-05-06 02:29:25.794404', '2026-05-06 02:29:25.794404', 'admin');
INSERT INTO public.batiments (id, identifiant, type, adresse, quartier, latitude, longitude, surface, etages, observations, date_creation, date_modification, cree_par) VALUES (21, 'BAT-OUA-2026-031', 'Équipement public', 'Rue 12, Ouakam Nord', 'Ouakam Nord', 14.718320, -17.469110, 500, 'RDC', 'Ajouté le 06/05/2026', '2026-05-06 02:35:34.422555', '2026-05-06 02:35:34.422555', 'admin');
INSERT INTO public.batiments (id, identifiant, type, adresse, quartier, latitude, longitude, surface, etages, observations, date_creation, date_modification, cree_par) VALUES (24, 'BAT-OUA-2026-039', '', 'Rue 12, Ouakam Nord', 'Ouakam Nord', 14.718320, -17.469110, 700, 'R+1', 'Ajouté le 06/05/2026', '2026-05-06 02:40:02.713985', '2026-05-06 02:41:04.88582', 'admin');
INSERT INTO public.batiments (id, identifiant, type, adresse, quartier, latitude, longitude, surface, etages, observations, date_creation, date_modification, cree_par) VALUES (22, 'BAT-OUA-2026-038', 'Mixte', 'Rue 12, Ouakam Nord', 'Ouakam Nord', 14.718328, -17.469119, 800, 'R+3', 'Ajouté le 06/05/2026', '2026-05-06 02:38:22.484049', '2026-05-06 02:43:26.485413', 'admin');
INSERT INTO public.batiments (id, identifiant, type, adresse, quartier, latitude, longitude, surface, etages, observations, date_creation, date_modification, cree_par) VALUES (28, 'BAT-OUA-2026-065', 'Commercial', 'Rue 12, Ouakam Nord', 'Ouakam Nord', 14.718100, -17.469310, 400, 'R+3', 'Ajouté le 23/05/2026', '2026-05-23 16:40:54.379719', '2026-05-23 16:40:54.379719', 'admin');


--
-- TOC entry 4360 (class 0 OID 31366)
-- Dependencies: 235
-- Data for Name: commerces; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.commerces (id, nom, type, adresse, latitude, longitude, proprietaire, telephone, observations, date_creation, statut, cree_par) VALUES (1, 'Supermarché Auchan', 'Supermarché', 'Avenue Cheikh Anta Diop, N° 45', 14.718, -17.469, 'Ibrahima Sarr', '+221 76 512 88 03', '', '2026-05-06 04:57:49.941871', 'actif', 'admin');


--
-- TOC entry 4346 (class 0 OID 31291)
-- Dependencies: 221
-- Data for Name: compteurs; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.compteurs (id, nom, valeur, date_mise_a_jour) VALUES (2, 'alertes_rues', 3, '2026-05-05 14:24:08.272231');
INSERT INTO public.compteurs (id, nom, valeur, date_mise_a_jour) VALUES (3, 'total_parcelles', 5200, '2026-05-05 14:24:08.272231');
INSERT INTO public.compteurs (id, nom, valeur, date_mise_a_jour) VALUES (4, 'actifs_terrain', 12, '2026-05-05 14:24:08.272231');
INSERT INTO public.compteurs (id, nom, valeur, date_mise_a_jour) VALUES (1, 'total_batiments', 12450, '2026-05-23 16:40:54.402929');


--
-- TOC entry 4362 (class 0 OID 31377)
-- Dependencies: 237
-- Data for Name: controles; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.controles (id, numero_parcelle, type_controle, zone_reglementaire, observations, statut, date_controle, controle_par) VALUES (1, 'PARC-TEST-001', 'Permis de construire', 'Zone mixte', 'Conforme', 'conforme', '2026-05-06 04:53:02.573977', 'admin');
INSERT INTO public.controles (id, numero_parcelle, type_controle, zone_reglementaire, observations, statut, date_controle, controle_par) VALUES (2, 'OUA-PARC-2024-045', 'Respect COS/CES', 'Zone mixte (M1)', 'Construction conforme au PLU. Permis valide.', 'conforme', '2026-05-06 05:03:02.307432', 'admin');


--
-- TOC entry 4350 (class 0 OID 31315)
-- Dependencies: 225
-- Data for Name: infrastructures; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.infrastructures (id, nom, categorie, latitude, longitude, icone, couleur, date_creation) VALUES (1, 'Voie principale', 'Voie', 14.720000, -17.467000, 'route', '#F5A623', '2026-05-05 14:24:08.272231');
INSERT INTO public.infrastructures (id, nom, categorie, latitude, longitude, icone, couleur, date_creation) VALUES (2, 'Voie secondaire', 'Voie', 14.716000, -17.469000, 'route', '#F5A623', '2026-05-05 14:24:08.272231');
INSERT INTO public.infrastructures (id, nom, categorie, latitude, longitude, icone, couleur, date_creation) VALUES (3, 'Poste électrique', 'Électricité', 14.718000, -17.464000, 'zap', '#F5A623', '2026-05-05 14:24:08.272231');
INSERT INTO public.infrastructures (id, nom, categorie, latitude, longitude, icone, couleur, date_creation) VALUES (4, 'Pharmacie centrale', 'Pharmacie', 14.715000, -17.466500, 'pill', '#C0392B', '2026-05-05 14:24:08.272231');
INSERT INTO public.infrastructures (id, nom, categorie, latitude, longitude, icone, couleur, date_creation) VALUES (5, 'Centre de santé', 'Santé', 14.721000, -17.471000, 'hospital', '#C0392B', '2026-05-05 14:24:08.272231');
INSERT INTO public.infrastructures (id, nom, categorie, latitude, longitude, icone, couleur, date_creation) VALUES (6, 'École primaire', 'École', 14.719500, -17.464800, 'graduation-cap', '#4A7C5F', '2026-05-05 14:24:08.272231');
INSERT INTO public.infrastructures (id, nom, categorie, latitude, longitude, icone, couleur, date_creation) VALUES (7, 'Mosquée centrale', 'Mosquée', 14.714500, -17.468800, 'mosque', '#B8860B', '2026-05-05 14:24:08.272231');
INSERT INTO public.infrastructures (id, nom, categorie, latitude, longitude, icone, couleur, date_creation) VALUES (8, 'Mairie', 'Administration', 14.717500, -17.467800, 'building-2', '#1A6B45', '2026-05-05 14:24:08.272231');


--
-- TOC entry 4352 (class 0 OID 31323)
-- Dependencies: 227
-- Data for Name: paiements; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.paiements (id, reference, contribuable, nicad, montant, statut, mode_paiement, date_paiement, observations, date_creation, numero_recu, cree_par) VALUES (2, 'TAX-2024', 'Mamadou Ndiaye', '14.12.01.09.441', 75000, 'overdue', NULL, NULL, NULL, '2026-05-05 14:24:08.272231', NULL, NULL);
INSERT INTO public.paiements (id, reference, contribuable, nicad, montant, statut, mode_paiement, date_paiement, observations, date_creation, numero_recu, cree_par) VALUES (6, 'TEST-001', 'TEST CONTRIBUABLE', NULL, 100000, 'paye', 'Mobile Money', '2026-05-06 00:00:00', NULL, '2026-05-06 04:53:02.566331', 'REC-001', 'admin');
INSERT INTO public.paiements (id, reference, contribuable, nicad, montant, statut, mode_paiement, date_paiement, observations, date_creation, numero_recu, cree_par) VALUES (7, 'PAY-2026-001', 'Boutique Salam', NULL, 150000, 'paye', 'Mobile Money', '2026-05-06 00:00:00', 'Paiement mensuel', '2026-05-06 05:00:26.733206', 'REC-001', 'agent');
INSERT INTO public.paiements (id, reference, contribuable, nicad, montant, statut, mode_paiement, date_paiement, observations, date_creation, numero_recu, cree_par) VALUES (8, 'FIS-OUA-2026-001', 'Boutique dioulde', NULL, 450000, 'paye', 'Mobile Money', '2026-05-01 00:00:00', '', '2026-05-06 05:02:26.189365', 'REC-2026-089', 'admin');
INSERT INTO public.paiements (id, reference, contribuable, nicad, montant, statut, mode_paiement, date_paiement, observations, date_creation, numero_recu, cree_par) VALUES (16, 'PAY-001', 'Boutique Salam', NULL, 250000, 'paye', NULL, NULL, NULL, '2026-05-06 17:14:20.877053', NULL, NULL);
INSERT INTO public.paiements (id, reference, contribuable, nicad, montant, statut, mode_paiement, date_paiement, observations, date_creation, numero_recu, cree_par) VALUES (17, 'PAY-002', 'Pharmacie Centrale', NULL, 180000, 'paye', NULL, NULL, NULL, '2026-05-06 17:14:20.877053', NULL, NULL);
INSERT INTO public.paiements (id, reference, contribuable, nicad, montant, statut, mode_paiement, date_paiement, observations, date_creation, numero_recu, cree_par) VALUES (18, 'PAY-003', 'Marché Ouakam', NULL, 95000, 'overdue', NULL, NULL, NULL, '2026-05-06 17:14:20.877053', NULL, NULL);
INSERT INTO public.paiements (id, reference, contribuable, nicad, montant, statut, mode_paiement, date_paiement, observations, date_creation, numero_recu, cree_par) VALUES (19, 'PAY-004', 'Station Total', NULL, 320000, 'paye', NULL, NULL, NULL, '2026-05-06 17:14:20.877053', NULL, NULL);
INSERT INTO public.paiements (id, reference, contribuable, nicad, montant, statut, mode_paiement, date_paiement, observations, date_creation, numero_recu, cree_par) VALUES (22, 'FIS-OUA-840', 'Awa Gueye', NULL, 26000, 'pending', 'Espèces', '2026-05-06 00:00:00', '', '2026-05-06 22:41:06.649295', NULL, 'admin');


--
-- TOC entry 4356 (class 0 OID 31346)
-- Dependencies: 231
-- Data for Name: rapports; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.rapports (id, titre, type_rapport, periode, format, contenu, fichier_url, genere_par, date_generation) VALUES (1, 'Rapport des Retards de Paiement', 'overdue', 'quarter', 'excel', '
        <div style="font-family: ''Inter'', sans-serif; padding: 20px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h2 style="color: #1A6B45;">UNCO - Rapport des Retards de Paiement</h2>
                <p>Généré le 06/05/2026 03:34:35</p>
                <p>Période: Dernier trimestre</p>
            </div>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #1A6B45; color: white;">
                        <th style="padding: 10px; text-align: left;">Contribuable</th><th style="padding: 10px; text-align: left;">Reference</th><th style="padding: 10px; text-align: left;">Montant</th><th style="padding: 10px; text-align: left;">Nicad</th>
                    </tr>
                </thead>
                <tbody>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Mamadou Ndiaye</td><td style="padding: 8px;">TAX-2024</td><td style="padding: 8px;">75 000</td><td style="padding: 8px;">14.12.01.09.441</td>
                        </tr>
                    
                </tbody>
            </table>
            <div style="margin-top: 30px; text-align: center; font-size: 0.7rem; color: #666;">
                Document généré par UNCO - Système de Gestion Urbaine et Fiscale
            </div>
        </div>
    ', NULL, 'admin', '2026-05-06 03:34:35.573');
INSERT INTO public.rapports (id, titre, type_rapport, periode, format, contenu, fichier_url, genere_par, date_generation) VALUES (2, 'Rapport de Recouvrement Fiscal', 'fiscal', 'quarter', 'excel', '
        <div style="font-family: ''Inter'', sans-serif; padding: 20px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h2 style="color: #1A6B45;">UNCO - Rapport de Recouvrement Fiscal</h2>
                <p>Généré le 06/05/2026 03:35:42</p>
                <p>Période: Dernier trimestre</p>
            </div>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #1A6B45; color: white;">
                        <th style="padding: 10px; text-align: left;">Indicateur</th><th style="padding: 10px; text-align: left;">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Total collecté</td><td style="padding: 8px;">885 000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En attente</td><td style="padding: 8px;">200 000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En retard</td><td style="padding: 8px;">75 000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Taux de recouvrement</td><td style="padding: 8px;">76%</td>
                        </tr>
                    
                </tbody>
            </table>
            <div style="margin-top: 30px; text-align: center; font-size: 0.7rem; color: #666;">
                Document généré par UNCO - Système de Gestion Urbaine et Fiscale
            </div>
        </div>
    ', NULL, 'admin', '2026-05-06 03:35:42.404');
INSERT INTO public.rapports (id, titre, type_rapport, periode, format, contenu, fichier_url, genere_par, date_generation) VALUES (3, 'Rapport de Recouvrement Fiscal', 'fiscal', 'quarter', 'pdf', '
        <div style="font-family: ''Inter'', sans-serif; padding: 20px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h2 style="color: #1A6B45;">UNCO - Rapport de Recouvrement Fiscal</h2>
                <p>Généré le 06/05/2026 03:36:35</p>
                <p>Période: Dernier trimestre</p>
            </div>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #1A6B45; color: white;">
                        <th style="padding: 10px; text-align: left;">Indicateur</th><th style="padding: 10px; text-align: left;">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Total collecté</td><td style="padding: 8px;">885 000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En attente</td><td style="padding: 8px;">200 000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En retard</td><td style="padding: 8px;">75 000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Taux de recouvrement</td><td style="padding: 8px;">76%</td>
                        </tr>
                    
                </tbody>
            </table>
            <div style="margin-top: 30px; text-align: center; font-size: 0.7rem; color: #666;">
                Document généré par UNCO - Système de Gestion Urbaine et Fiscale
            </div>
        </div>
    ', NULL, 'admin', '2026-05-06 03:36:35.766');
INSERT INTO public.rapports (id, titre, type_rapport, periode, format, contenu, fichier_url, genere_par, date_generation) VALUES (4, 'Rapport de Recouvrement Fiscal', 'fiscal', 'quarter', 'excel', '
        <div style="font-family: ''Inter'', sans-serif; padding: 20px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h2 style="color: #1A6B45;">UNCO - Rapport de Recouvrement Fiscal</h2>
                <p>Généré le 06/05/2026 04:17:56</p>
                <p>Période: Dernier trimestre</p>
            </div>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #1A6B45; color: white;">
                        <th style="padding: 10px; text-align: left;">Indicateur</th><th style="padding: 10px; text-align: left;">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Total collecté</td><td style="padding: 8px;">885 000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En attente</td><td style="padding: 8px;">200 000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En retard</td><td style="padding: 8px;">75 000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Taux de recouvrement</td><td style="padding: 8px;">76%</td>
                        </tr>
                    
                </tbody>
            </table>
            <div style="margin-top: 30px; text-align: center; font-size: 0.7rem; color: #666;">
                Document généré par UNCO - Système de Gestion Urbaine et Fiscale
            </div>
        </div>
    ', NULL, 'admin', '2026-05-06 04:17:56.776');
INSERT INTO public.rapports (id, titre, type_rapport, periode, format, contenu, fichier_url, genere_par, date_generation) VALUES (5, 'Rapport de Recouvrement Fiscal', 'fiscal', 'quarter', 'excel', '
        <div style="font-family: ''Inter'', sans-serif; padding: 20px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h2 style="color: #1A6B45;">UNCO - Rapport de Recouvrement Fiscal</h2>
                <p>Généré le 06/05/2026 06:43:31</p>
                <p>Période: Dernier trimestre</p>
            </div>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #1A6B45; color: white;">
                        <th style="padding: 10px; text-align: left;">Indicateur</th><th style="padding: 10px; text-align: left;">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Total collecté</td><td style="padding: 8px;">700000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En attente</td><td style="padding: 8px;">200000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En retard</td><td style="padding: 8px;">75000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Taux de recouvrement</td><td style="padding: 8px;">0%</td>
                        </tr>
                    
                </tbody>
            </table>
            <div style="margin-top: 30px; text-align: center; font-size: 0.7rem; color: #666;">
                Document généré par UNCO - Système de Gestion Urbaine et Fiscale
            </div>
        </div>
    ', NULL, 'admin', '2026-05-06 06:43:31.626');
INSERT INTO public.rapports (id, titre, type_rapport, periode, format, contenu, fichier_url, genere_par, date_generation) VALUES (6, 'Rapport de Recouvrement Fiscal', 'fiscal', 'quarter', 'excel', '
        <div style="font-family: ''Inter'', sans-serif; padding: 20px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h2 style="color: #1A6B45;">UNCO - Rapport de Recouvrement Fiscal</h2>
                <p>Généré le 06/05/2026 11:19:06</p>
                <p>Période: Dernier trimestre</p>
            </div>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #1A6B45; color: white;">
                        <th style="padding: 10px; text-align: left;">Indicateur</th><th style="padding: 10px; text-align: left;">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Total collecté</td><td style="padding: 8px;">700000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En attente</td><td style="padding: 8px;">200000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En retard</td><td style="padding: 8px;">75000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Taux de recouvrement</td><td style="padding: 8px;">0%</td>
                        </tr>
                    
                </tbody>
            </table>
            <div style="margin-top: 30px; text-align: center; font-size: 0.7rem; color: #666;">
                Document généré par UNCO - Système de Gestion Urbaine et Fiscale
            </div>
        </div>
    ', NULL, 'admin', '2026-05-06 11:19:06.954');
INSERT INTO public.rapports (id, titre, type_rapport, periode, format, contenu, fichier_url, genere_par, date_generation) VALUES (7, 'Rapport de Recouvrement Fiscal', 'fiscal', 'quarter', 'excel', '
        <div style="font-family: ''Inter'', sans-serif; padding: 20px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h2 style="color: #1A6B45;">UNCO - Rapport de Recouvrement Fiscal</h2>
                <p>Généré le 10/05/2026 18:12:36</p>
                <p>Période: Dernier trimestre</p>
            </div>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #1A6B45; color: white;">
                        <th style="padding: 10px; text-align: left;">Indicateur</th><th style="padding: 10px; text-align: left;">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Total collecté</td><td style="padding: 8px;">1450000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En attente</td><td style="padding: 8px;">26000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En retard</td><td style="padding: 8px;">170000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Taux de recouvrement</td><td style="padding: 8px;">0%</td>
                        </tr>
                    
                </tbody>
            </table>
            <div style="margin-top: 30px; text-align: center; font-size: 0.7rem; color: #666;">
                Document généré par UNCO - Système de Gestion Urbaine et Fiscale
            </div>
        </div>
    ', NULL, 'admin', '2026-05-10 18:12:36.965');
INSERT INTO public.rapports (id, titre, type_rapport, periode, format, contenu, fichier_url, genere_par, date_generation) VALUES (8, 'Rapport de Recouvrement Fiscal', 'fiscal', 'quarter', 'pdf', '
        <div style="font-family: ''Inter'', sans-serif; padding: 20px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h2 style="color: #1A6B45;">UNCO - Rapport de Recouvrement Fiscal</h2>
                <p>Généré le 10/05/2026 18:12:59</p>
                <p>Période: Dernier trimestre</p>
            </div>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #1A6B45; color: white;">
                        <th style="padding: 10px; text-align: left;">Indicateur</th><th style="padding: 10px; text-align: left;">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Total collecté</td><td style="padding: 8px;">1450000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En attente</td><td style="padding: 8px;">26000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En retard</td><td style="padding: 8px;">170000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Taux de recouvrement</td><td style="padding: 8px;">0%</td>
                        </tr>
                    
                </tbody>
            </table>
            <div style="margin-top: 30px; text-align: center; font-size: 0.7rem; color: #666;">
                Document généré par UNCO - Système de Gestion Urbaine et Fiscale
            </div>
        </div>
    ', NULL, 'admin', '2026-05-10 18:12:59.745');
INSERT INTO public.rapports (id, titre, type_rapport, periode, format, contenu, fichier_url, genere_par, date_generation) VALUES (9, 'Rapport de Recouvrement Fiscal', 'fiscal', 'quarter', 'excel', '
        <div style="font-family: ''Inter'', sans-serif; padding: 20px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h2 style="color: #1A6B45;">UNCO - Rapport de Recouvrement Fiscal</h2>
                <p>Généré le 23/05/2026 16:42:22</p>
                <p>Période: Dernier trimestre</p>
            </div>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #1A6B45; color: white;">
                        <th style="padding: 10px; text-align: left;">Indicateur</th><th style="padding: 10px; text-align: left;">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Total collecté</td><td style="padding: 8px;">1450000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En attente</td><td style="padding: 8px;">26000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En retard</td><td style="padding: 8px;">170000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Taux de recouvrement</td><td style="padding: 8px;">0%</td>
                        </tr>
                    
                </tbody>
            </table>
            <div style="margin-top: 30px; text-align: center; font-size: 0.7rem; color: #666;">
                Document généré par UNCO - Système de Gestion Urbaine et Fiscale
            </div>
        </div>
    ', NULL, 'admin', '2026-05-23 16:42:22.743');
INSERT INTO public.rapports (id, titre, type_rapport, periode, format, contenu, fichier_url, genere_par, date_generation) VALUES (10, 'Rapport de Recouvrement Fiscal', 'fiscal', 'quarter', 'pdf', '
        <div style="font-family: ''Inter'', sans-serif; padding: 20px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h2 style="color: #1A6B45;">UNCO - Rapport de Recouvrement Fiscal</h2>
                <p>Généré le 23/05/2026 16:42:55</p>
                <p>Période: Dernier trimestre</p>
            </div>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #1A6B45; color: white;">
                        <th style="padding: 10px; text-align: left;">Indicateur</th><th style="padding: 10px; text-align: left;">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Total collecté</td><td style="padding: 8px;">1450000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En attente</td><td style="padding: 8px;">26000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En retard</td><td style="padding: 8px;">170000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Taux de recouvrement</td><td style="padding: 8px;">0%</td>
                        </tr>
                    
                </tbody>
            </table>
            <div style="margin-top: 30px; text-align: center; font-size: 0.7rem; color: #666;">
                Document généré par UNCO - Système de Gestion Urbaine et Fiscale
            </div>
        </div>
    ', NULL, 'admin', '2026-05-23 16:42:56.01');
INSERT INTO public.rapports (id, titre, type_rapport, periode, format, contenu, fichier_url, genere_par, date_generation) VALUES (11, 'Rapport des Structures Recensées', 'buildings', 'month', 'pdf', '
        <div style="font-family: ''Inter'', sans-serif; padding: 20px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h2 style="color: #1A6B45;">UNCO - Rapport des Structures Recensées</h2>
                <p>Généré le 23/05/2026 16:50:42</p>
                <p>Période: Dernier mois</p>
            </div>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #1A6B45; color: white;">
                        <th style="padding: 10px; text-align: left;">Type</th><th style="padding: 10px; text-align: left;">Nombre</th>
                    </tr>
                </thead>
                <tbody>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Commercial</td><td style="padding: 8px;">3</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;"></td><td style="padding: 8px;">1</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Mixte</td><td style="padding: 8px;">3</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Équipement public</td><td style="padding: 8px;">2</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Résidentiel</td><td style="padding: 8px;">6</td>
                        </tr>
                    
                </tbody>
            </table>
            <div style="margin-top: 30px; text-align: center; font-size: 0.7rem; color: #666;">
                Document généré par UNCO - Système de Gestion Urbaine et Fiscale
            </div>
        </div>
    ', NULL, 'admin', '2026-05-23 16:50:42.312');
INSERT INTO public.rapports (id, titre, type_rapport, periode, format, contenu, fichier_url, genere_par, date_generation) VALUES (12, 'Rapport de Recouvrement Fiscal', 'fiscal', 'quarter', 'excel', '
        <div style="font-family: ''Inter'', sans-serif; padding: 20px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h2 style="color: #1A6B45;">UNCO - Rapport de Recouvrement Fiscal</h2>
                <p>Généré le 01/06/2026 21:45:06</p>
                <p>Période: Dernier trimestre</p>
            </div>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #1A6B45; color: white;">
                        <th style="padding: 10px; text-align: left;">Indicateur</th><th style="padding: 10px; text-align: left;">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Total collecté</td><td style="padding: 8px;">1450000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En attente</td><td style="padding: 8px;">26000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En retard</td><td style="padding: 8px;">170000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Taux de recouvrement</td><td style="padding: 8px;">0%</td>
                        </tr>
                    
                </tbody>
            </table>
            <div style="margin-top: 30px; text-align: center; font-size: 0.7rem; color: #666;">
                Document généré par UNCO - Système de Gestion Urbaine et Fiscale
            </div>
        </div>
    ', NULL, 'admin', '2026-06-01 21:45:06.347');
INSERT INTO public.rapports (id, titre, type_rapport, periode, format, contenu, fichier_url, genere_par, date_generation) VALUES (13, 'Rapport de Recouvrement Fiscal', 'fiscal', 'quarter', 'pdf', '
        <div style="font-family: ''Inter'', sans-serif; padding: 20px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h2 style="color: #1A6B45;">UNCO - Rapport de Recouvrement Fiscal</h2>
                <p>Généré le 01/06/2026 22:51:45</p>
                <p>Période: Dernier trimestre</p>
            </div>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #1A6B45; color: white;">
                        <th style="padding: 10px; text-align: left;">Indicateur</th><th style="padding: 10px; text-align: left;">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Total collecté</td><td style="padding: 8px;">1450000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En attente</td><td style="padding: 8px;">26000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En retard</td><td style="padding: 8px;">170000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Taux de recouvrement</td><td style="padding: 8px;">0%</td>
                        </tr>
                    
                </tbody>
            </table>
            <div style="margin-top: 30px; text-align: center; font-size: 0.7rem; color: #666;">
                Document généré par UNCO - Système de Gestion Urbaine et Fiscale
            </div>
        </div>
    ', NULL, 'admin', '2026-06-01 22:51:45.09');
INSERT INTO public.rapports (id, titre, type_rapport, periode, format, contenu, fichier_url, genere_par, date_generation) VALUES (14, 'Rapport de Recouvrement Fiscal', 'fiscal', 'quarter', 'excel', '
        <div style="font-family: ''Inter'', sans-serif; padding: 20px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h2 style="color: #1A6B45;">UNCO - Rapport de Recouvrement Fiscal</h2>
                <p>Généré le 01/06/2026 22:55:32</p>
                <p>Période: Dernier trimestre</p>
            </div>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #1A6B45; color: white;">
                        <th style="padding: 10px; text-align: left;">Indicateur</th><th style="padding: 10px; text-align: left;">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Total collecté</td><td style="padding: 8px;">1450000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En attente</td><td style="padding: 8px;">26000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En retard</td><td style="padding: 8px;">170000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Taux de recouvrement</td><td style="padding: 8px;">0%</td>
                        </tr>
                    
                </tbody>
            </table>
            <div style="margin-top: 30px; text-align: center; font-size: 0.7rem; color: #666;">
                Document généré par UNCO - Système de Gestion Urbaine et Fiscale
            </div>
        </div>
    ', NULL, 'admin', '2026-06-01 22:55:32.221');
INSERT INTO public.rapports (id, titre, type_rapport, periode, format, contenu, fichier_url, genere_par, date_generation) VALUES (15, 'Rapport de Recouvrement Fiscal', 'fiscal', 'quarter', 'excel', '
        <div style="font-family: ''Inter'', sans-serif; padding: 20px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h2 style="color: #1A6B45;">UNCO - Rapport de Recouvrement Fiscal</h2>
                <p>Généré le 02/06/2026 00:28:27</p>
                <p>Période: Dernier trimestre</p>
            </div>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #1A6B45; color: white;">
                        <th style="padding: 10px; text-align: left;">Indicateur</th><th style="padding: 10px; text-align: left;">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Total collecté</td><td style="padding: 8px;">1450000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En attente</td><td style="padding: 8px;">26000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En retard</td><td style="padding: 8px;">170000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Taux de recouvrement</td><td style="padding: 8px;">0%</td>
                        </tr>
                    
                </tbody>
            </table>
            <div style="margin-top: 30px; text-align: center; font-size: 0.7rem; color: #666;">
                Document généré par UNCO - Système de Gestion Urbaine et Fiscale
            </div>
        </div>
    ', NULL, 'admin', '2026-06-02 00:28:27.215');
INSERT INTO public.rapports (id, titre, type_rapport, periode, format, contenu, fichier_url, genere_par, date_generation) VALUES (16, 'Rapport de Recouvrement Fiscal', 'fiscal', 'quarter', 'pdf', '
        <div style="font-family: ''Inter'', sans-serif; padding: 20px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h2 style="color: #1A6B45;">UNCO - Rapport de Recouvrement Fiscal</h2>
                <p>Généré le 02/06/2026 00:28:46</p>
                <p>Période: Dernier trimestre</p>
            </div>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #1A6B45; color: white;">
                        <th style="padding: 10px; text-align: left;">Indicateur</th><th style="padding: 10px; text-align: left;">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Total collecté</td><td style="padding: 8px;">1450000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En attente</td><td style="padding: 8px;">26000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">En retard</td><td style="padding: 8px;">170000 FCFA</td>
                        </tr>
                    
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px;">Taux de recouvrement</td><td style="padding: 8px;">0%</td>
                        </tr>
                    
                </tbody>
            </table>
            <div style="margin-top: 30px; text-align: center; font-size: 0.7rem; color: #666;">
                Document généré par UNCO - Système de Gestion Urbaine et Fiscale
            </div>
        </div>
    ', NULL, 'admin', '2026-06-02 00:28:46.767');


--
-- TOC entry 4358 (class 0 OID 31356)
-- Dependencies: 233
-- Data for Name: recensements; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.recensements (id, nom_structure, type_structure, latitude, longitude, adresse, proprietaire, telephone, observations, date_creation, cree_par) VALUES (1, 'Pharmacie Centrale', 'Pharmacie', 14.7167, -17.4667, 'Avenue principale', 'Dr Diallo', '778889900', NULL, '2026-05-06 04:43:17.784707', 'admin');
INSERT INTO public.recensements (id, nom_structure, type_structure, latitude, longitude, adresse, proprietaire, telephone, observations, date_creation, cree_par) VALUES (2, 'TEST CONSOLE', 'Test', 14.7167, -17.4667, 'Test', 'Test', '771234567', 'Test', '2026-05-06 04:46:21.590614', 'admin');
INSERT INTO public.recensements (id, nom_structure, type_structure, latitude, longitude, adresse, proprietaire, telephone, observations, date_creation, cree_par) VALUES (3, 'Boutique Salam', 'Commerce', 14.7167, -17.4677, 'Cité Ensemble, Rue ASS-114', 'Assane Diop', '+221 77 823 45 12', '', '2026-05-06 04:48:01.637517', 'admin');


--
-- TOC entry 4364 (class 0 OID 31389)
-- Dependencies: 239
-- Data for Name: rues; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- TOC entry 4141 (class 0 OID 30525)
-- Dependencies: 216
-- Data for Name: spatial_ref_sys; Type: TABLE DATA; Schema: public; Owner: postgres
--



--
-- TOC entry 4354 (class 0 OID 31335)
-- Dependencies: 229
-- Data for Name: utilisateurs; Type: TABLE DATA; Schema: public; Owner: postgres
--

INSERT INTO public.utilisateurs (id, nom, email, mot_de_passe, role, actif, date_creation, derniere_connexion) VALUES (1, 'Administrateur', 'admin@unco.sn', '0192023a7bbd73250516f069df18b500', 'admin', true, '2026-05-05 14:24:08.272231', NULL);


--
-- TOC entry 4382 (class 0 OID 0)
-- Dependencies: 222
-- Name: batiments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.batiments_id_seq', 28, true);


--
-- TOC entry 4383 (class 0 OID 0)
-- Dependencies: 234
-- Name: commerces_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.commerces_id_seq', 1, true);


--
-- TOC entry 4384 (class 0 OID 0)
-- Dependencies: 220
-- Name: compteurs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.compteurs_id_seq', 4, true);


--
-- TOC entry 4385 (class 0 OID 0)
-- Dependencies: 236
-- Name: controles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.controles_id_seq', 2, true);


--
-- TOC entry 4386 (class 0 OID 0)
-- Dependencies: 224
-- Name: infrastructures_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.infrastructures_id_seq', 8, true);


--
-- TOC entry 4387 (class 0 OID 0)
-- Dependencies: 226
-- Name: paiements_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.paiements_id_seq', 22, true);


--
-- TOC entry 4388 (class 0 OID 0)
-- Dependencies: 230
-- Name: rapports_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.rapports_id_seq', 16, true);


--
-- TOC entry 4389 (class 0 OID 0)
-- Dependencies: 232
-- Name: recensements_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.recensements_id_seq', 3, true);


--
-- TOC entry 4390 (class 0 OID 0)
-- Dependencies: 238
-- Name: rues_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.rues_id_seq', 1, false);


--
-- TOC entry 4391 (class 0 OID 0)
-- Dependencies: 228
-- Name: utilisateurs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.utilisateurs_id_seq', 1, true);


--
-- TOC entry 4175 (class 2606 OID 31313)
-- Name: batiments batiments_identifiant_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.batiments
    ADD CONSTRAINT batiments_identifiant_key UNIQUE (identifiant);


--
-- TOC entry 4177 (class 2606 OID 31311)
-- Name: batiments batiments_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.batiments
    ADD CONSTRAINT batiments_pkey PRIMARY KEY (id);


--
-- TOC entry 4193 (class 2606 OID 31375)
-- Name: commerces commerces_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.commerces
    ADD CONSTRAINT commerces_pkey PRIMARY KEY (id);


--
-- TOC entry 4171 (class 2606 OID 31300)
-- Name: compteurs compteurs_nom_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.compteurs
    ADD CONSTRAINT compteurs_nom_key UNIQUE (nom);


--
-- TOC entry 4173 (class 2606 OID 31298)
-- Name: compteurs compteurs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.compteurs
    ADD CONSTRAINT compteurs_pkey PRIMARY KEY (id);


--
-- TOC entry 4195 (class 2606 OID 31386)
-- Name: controles controles_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.controles
    ADD CONSTRAINT controles_pkey PRIMARY KEY (id);


--
-- TOC entry 4179 (class 2606 OID 31321)
-- Name: infrastructures infrastructures_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.infrastructures
    ADD CONSTRAINT infrastructures_pkey PRIMARY KEY (id);


--
-- TOC entry 4181 (class 2606 OID 31331)
-- Name: paiements paiements_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.paiements
    ADD CONSTRAINT paiements_pkey PRIMARY KEY (id);


--
-- TOC entry 4183 (class 2606 OID 31333)
-- Name: paiements paiements_reference_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.paiements
    ADD CONSTRAINT paiements_reference_key UNIQUE (reference);


--
-- TOC entry 4189 (class 2606 OID 31354)
-- Name: rapports rapports_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.rapports
    ADD CONSTRAINT rapports_pkey PRIMARY KEY (id);


--
-- TOC entry 4191 (class 2606 OID 31364)
-- Name: recensements recensements_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recensements
    ADD CONSTRAINT recensements_pkey PRIMARY KEY (id);


--
-- TOC entry 4197 (class 2606 OID 31395)
-- Name: rues rues_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.rues
    ADD CONSTRAINT rues_pkey PRIMARY KEY (id);


--
-- TOC entry 4185 (class 2606 OID 31344)
-- Name: utilisateurs utilisateurs_email_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.utilisateurs
    ADD CONSTRAINT utilisateurs_email_key UNIQUE (email);


--
-- TOC entry 4187 (class 2606 OID 31342)
-- Name: utilisateurs utilisateurs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.utilisateurs
    ADD CONSTRAINT utilisateurs_pkey PRIMARY KEY (id);


-- Completed on 2026-06-04 23:52:22

--
-- PostgreSQL database dump complete
--

