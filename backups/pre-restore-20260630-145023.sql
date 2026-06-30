-- MySQL dump 10.13  Distrib 8.4.9, for Linux (x86_64)
--
-- Host: localhost    Database: laravel
-- ------------------------------------------------------
-- Server version	8.4.9

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `action_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_email_snapshot` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_name_snapshot` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `revision_number` int unsigned DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `activity_logs_user_id_foreign` (`user_id`),
  KEY `activity_logs_project_id_foreign` (`project_id`),
  KEY `activity_logs_action_type_index` (`action_type`),
  KEY `activity_logs_created_at_index` (`created_at`),
  KEY `activity_logs_revision_number_index` (`revision_number`),
  CONSTRAINT `activity_logs_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `activity_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
INSERT INTO `cache` VALUES ('luxquote-cache-livewire-rate-limiter:4e77e1f00df39b43a8a74dad60db8ad318758936','i:2;',1782826673),('luxquote-cache-livewire-rate-limiter:4e77e1f00df39b43a8a74dad60db8ad318758936:timer','i:1782826673;',1782826673);
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `document_pack_items`
--

DROP TABLE IF EXISTS `document_pack_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_pack_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_pack_id` bigint unsigned NOT NULL,
  `role` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int unsigned NOT NULL,
  `file_disk` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `original_filename` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `configuration` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_pack_items_document_pack_id_sort_order_index` (`document_pack_id`,`sort_order`),
  CONSTRAINT `document_pack_items_document_pack_id_foreign` FOREIGN KEY (`document_pack_id`) REFERENCES `document_packs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `document_pack_items`
--

LOCK TABLES `document_pack_items` WRITE;
/*!40000 ALTER TABLE `document_pack_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `document_pack_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `document_packs`
--

DROP TABLE IF EXISTS `document_packs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_packs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_packs_project_id_name_unique` (`project_id`,`name`),
  KEY `document_packs_created_by_foreign` (`created_by`),
  KEY `document_packs_updated_by_foreign` (`updated_by`),
  CONSTRAINT `document_packs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_packs_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_packs_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `document_packs`
--

LOCK TABLES `document_packs` WRITE;
/*!40000 ALTER TABLE `document_packs` DISABLE KEYS */;
/*!40000 ALTER TABLE `document_packs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` smallint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2026_05_26_113233_create_products_table',1),(5,'2026_05_26_123047_add_role_to_users_table',1),(6,'2026_05_26_140751_create_projects_table',1),(7,'2026_05_26_145950_create_project_areas_table',1),(8,'2026_05_26_145954_create_project_lines_table',1),(9,'2026_05_27_093102_add_product_id_to_project_lines_table',1),(10,'2026_05_27_094040_add_ref_to_project_lines_table',1),(11,'2026_05_27_095934_update_project_line_type_values',1),(12,'2026_05_27_143409_create_project_revisions_table',1),(13,'2026_05_27_143411_add_project_revision_id_to_project_areas_table',1),(14,'2026_05_27_143413_add_active_revision_id_to_projects_table',1),(15,'2026_05_27_145733_add_last_edited_at_to_projects_table',1),(16,'2026_05_27_151351_create_project_presences_table',1),(17,'2026_05_27_154837_add_last_edited_by_to_projects_table',1),(18,'2026_05_28_074312_create_activity_logs_table',1),(19,'2026_05_28_090240_add_profile_fields_to_users_table',1),(20,'2026_05_29_094932_add_salesforce_project_to_projects_table',1),(21,'2026_06_02_074020_add_two_factor_authentication_to_users_table',1),(22,'2026_06_03_152232_add_revision_number_to_activity_logs_table',1),(23,'2026_06_04_144356_add_approval_status_to_project_lines_table',1),(24,'2026_06_04_144356_add_validation_status_to_project_revisions_table',1),(25,'2026_06_05_082354_add_price_to_products_table',1),(26,'2026_06_05_085721_populate_project_line_prices_from_products',1),(27,'2026_06_08_085826_add_status_to_project_revisions_table',1),(28,'2026_06_08_105857_add_v_description_to_products_table',1),(29,'2026_06_08_140809_make_project_line_spacer_fields_nullable',1),(30,'2026_06_08_144825_add_salesforce_id_to_projects_table',1),(31,'2026_06_10_100225_add_validation_flagged_to_project_lines_table',1),(32,'2026_06_10_132500_add_value_and_make_cover_text_on_projects_table',1),(33,'2026_06_11_092942_make_project_name_snapshot_nullable_on_activity_logs_table',1),(34,'2026_06_12_083946_create_permission_groups_table',1),(35,'2026_06_12_083948_create_permissions_table',1),(36,'2026_06_23_105134_change_default_project_revision_to_zero',1),(37,'2026_06_23_141236_create_document_packs_table',1),(38,'2026_06_23_141240_create_document_pack_items_table',1),(39,'2026_06_23_141254_add_document_pack_permissions',1),(40,'2026_06_29_151327_add_last_login_at_to_users_table',1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permission_group_permission`
--

DROP TABLE IF EXISTS `permission_group_permission`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permission_group_permission` (
  `permission_group_id` bigint unsigned NOT NULL,
  `permission_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`permission_group_id`,`permission_id`),
  KEY `permission_group_permission_permission_id_foreign` (`permission_id`),
  CONSTRAINT `permission_group_permission_permission_group_id_foreign` FOREIGN KEY (`permission_group_id`) REFERENCES `permission_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `permission_group_permission_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permission_group_permission`
--

LOCK TABLES `permission_group_permission` WRITE;
/*!40000 ALTER TABLE `permission_group_permission` DISABLE KEYS */;
INSERT INTO `permission_group_permission` VALUES (1,1),(2,1),(3,1),(4,1),(5,1),(1,2),(2,2),(5,2),(1,3),(2,3),(5,3),(1,4),(2,4),(4,4),(5,4),(1,5),(2,5),(5,5),(1,6),(2,6),(3,6),(4,6),(5,6),(1,7),(5,7),(1,8),(3,8),(4,8),(5,8),(1,9),(4,9),(5,9),(1,10),(4,10),(5,10),(1,11),(4,11),(5,11),(1,12),(4,12),(5,12),(1,13),(5,13),(1,14),(5,14),(1,15),(2,15),(3,15),(4,15),(5,15),(1,16),(2,16),(3,16),(4,16),(5,16),(1,17),(3,17),(5,17),(1,18),(3,18),(5,18),(1,19),(2,19),(3,19),(4,19),(5,19),(1,20),(2,20),(3,20),(4,20),(5,20),(1,21),(3,21),(5,21),(1,22),(3,22),(5,22),(1,23),(3,23),(5,23),(1,24),(5,24),(1,25),(1,26),(5,26),(1,27),(1,28),(1,29),(1,30),(1,31);
/*!40000 ALTER TABLE `permission_group_permission` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permission_groups`
--

DROP TABLE IF EXISTS `permission_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permission_groups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permission_groups_slug_unique` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permission_groups`
--

LOCK TABLES `permission_groups` WRITE;
/*!40000 ALTER TABLE `permission_groups` DISABLE KEYS */;
INSERT INTO `permission_groups` VALUES (1,'Admin','admin','Everything unrestricted.',1,'2026-06-30 13:33:07','2026-06-30 13:33:07'),(2,'User','user','Project entry and unpriced schedule access.',1,'2026-06-30 13:33:07','2026-06-30 13:33:07'),(3,'Sales','sales','Pricing and customer output access.',1,'2026-06-30 13:33:07','2026-06-30 13:33:07'),(4,'Technical','technical','Schedule and validation access without pricing.',1,'2026-06-30 13:33:07','2026-06-30 13:33:07'),(5,'Manager','manager','Project management, pricing, approval, and reporting access.',1,'2026-06-30 13:33:07','2026-06-30 13:33:07');
/*!40000 ALTER TABLE `permission_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_key_unique` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'projects.view','View projects','Projects','View projects','2026-06-30 13:33:07','2026-06-30 13:33:07'),(2,'projects.create','Create projects','Projects','Create projects','2026-06-30 13:33:07','2026-06-30 13:33:07'),(3,'projects.update-details','Edit project details','Projects','Edit project details','2026-06-30 13:33:07','2026-06-30 13:33:07'),(4,'projects.update-lines','Edit project areas / line items','Projects','Edit project areas / line items','2026-06-30 13:33:07','2026-06-30 13:33:07'),(5,'revisions.create','Create project revisions','Revisions','Create project revisions','2026-06-30 13:33:07','2026-06-30 13:33:07'),(6,'project-history.view','View project history','Revisions','View project history','2026-06-30 13:33:07','2026-06-30 13:33:07'),(7,'activity-log.view','View global history','History','View global history','2026-06-30 13:33:07','2026-06-30 13:33:07'),(8,'validation.view','View validation page','Validation','View validation page','2026-06-30 13:33:07','2026-06-30 13:33:07'),(9,'validation.run','Run validation','Validation','Run validation','2026-06-30 13:33:07','2026-06-30 13:33:07'),(10,'validation.update-lines','Edit validation line items','Validation','Edit validation line items','2026-06-30 13:33:07','2026-06-30 13:33:07'),(11,'validation.flag-lines','Flag validation line items','Validation','Flag validation line items','2026-06-30 13:33:07','2026-06-30 13:33:07'),(12,'validation.merge-lines','Merge validation line items','Validation','Merge validation line items','2026-06-30 13:33:07','2026-06-30 13:33:07'),(13,'validation.approve-lines','Approve validation line items','Validation','Approve validation line items','2026-06-30 13:33:07','2026-06-30 13:33:07'),(14,'revisions.approve','Approve and lock project revision','Revisions','Approve and lock project revision','2026-06-30 13:33:07','2026-06-30 13:33:07'),(15,'output.view','View output page','Output','View output page','2026-06-30 13:33:07','2026-06-30 13:33:07'),(16,'output.produce-unpriced-schedule','Produce unpriced schedule','Output','Produce unpriced schedule','2026-06-30 13:33:07','2026-06-30 13:33:07'),(17,'output.produce-priced-schedule','Produce priced schedule','Output','Produce priced schedule','2026-06-30 13:33:07','2026-06-30 13:33:07'),(18,'output.produce-quote','Produce quote','Output','Produce quote','2026-06-30 13:33:07','2026-06-30 13:33:07'),(19,'output.manage-document-packs','Manage document packs','Output','Manage document packs','2026-06-30 13:33:07','2026-06-30 13:33:07'),(20,'output.produce-document-packs','Produce document packs','Output','Produce document packs','2026-06-30 13:33:07','2026-06-30 13:33:07'),(21,'quote-approval.request','Request quote approval','Output','Request quote approval','2026-06-30 13:33:07','2026-06-30 13:33:07'),(22,'pricing.view','View prices','Pricing','Global switch for price columns and price-based outputs.','2026-06-30 13:33:07','2026-06-30 13:33:07'),(23,'pricing.update','Edit prices','Pricing','Allows changing project line prices. Requires price visibility.','2026-06-30 13:33:07','2026-06-30 13:33:07'),(24,'products.view','View products list page','Products','View products list page','2026-06-30 13:33:07','2026-06-30 13:33:07'),(25,'products.import','Import / fetch products','Products','Import / fetch products','2026-06-30 13:33:07','2026-06-30 13:33:07'),(26,'salesforce.view','View Salesforce projects list page','Salesforce','View Salesforce projects list page','2026-06-30 13:33:07','2026-06-30 13:33:07'),(27,'users.view','View users list page','Users & Admin','View users list page','2026-06-30 13:33:07','2026-06-30 13:33:07'),(28,'users.create','Create users','Users & Admin','Create users','2026-06-30 13:33:07','2026-06-30 13:33:07'),(29,'users.update','Edit users','Users & Admin','Edit users','2026-06-30 13:33:07','2026-06-30 13:33:07'),(30,'users.delete','Delete users','Users & Admin','Delete users','2026-06-30 13:33:07','2026-06-30 13:33:07'),(31,'permissions.manage','Manage groups / permissions','Users & Admin','Allows managing permission groups and viewing the permission catalogue.','2026-06-30 13:33:07','2026-06-30 13:33:07');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `site` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `v_description` text COLLATE utf8mb4_unicode_ci,
  `type_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `length_mm` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `width_mm` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `depth_mm` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `diameter_mm` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cut_out_mm` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `weight_kg` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `luminaire_wattage_w` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lumens_lm` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `efficacy_llm_w` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `beam_angle_fwhm` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emergency_lumen_output` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `power` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `em_power` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cct_k` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `colour_temp` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cri` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dali` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vision_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emergency_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_rating` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ik_rating` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `electrical_class` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rl_ral` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `products_sku_unique` (`sku`),
  KEY `products_site_index` (`site`),
  KEY `products_product_name_index` (`product_name`),
  KEY `products_type_name_index` (`type_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `project_areas`
--

DROP TABLE IF EXISTS `project_areas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_areas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `project_revision_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_areas_project_id_foreign` (`project_id`),
  KEY `project_areas_project_revision_id_foreign` (`project_revision_id`),
  CONSTRAINT `project_areas_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_areas_project_revision_id_foreign` FOREIGN KEY (`project_revision_id`) REFERENCES `project_revisions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `project_areas`
--

LOCK TABLES `project_areas` WRITE;
/*!40000 ALTER TABLE `project_areas` DISABLE KEYS */;
/*!40000 ALTER TABLE `project_areas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `project_lines`
--

DROP TABLE IF EXISTS `project_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_area_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ref` varchar(6) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qty` int unsigned DEFAULT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'standard',
  `unit_price` decimal(10,2) DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approved` tinyint(1) NOT NULL DEFAULT '0',
  `approved_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `validation_flagged` tinyint(1) NOT NULL DEFAULT '0',
  `validation_note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_lines_project_area_id_foreign` (`project_area_id`),
  KEY `project_lines_product_id_foreign` (`product_id`),
  KEY `project_lines_approved_by_foreign` (`approved_by`),
  CONSTRAINT `project_lines_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_lines_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_lines_project_area_id_foreign` FOREIGN KEY (`project_area_id`) REFERENCES `project_areas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `project_lines`
--

LOCK TABLES `project_lines` WRITE;
/*!40000 ALTER TABLE `project_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `project_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `project_presences`
--

DROP TABLE IF EXISTS `project_presences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_presences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `last_seen_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_presences_project_id_user_id_unique` (`project_id`,`user_id`),
  KEY `project_presences_user_id_foreign` (`user_id`),
  CONSTRAINT `project_presences_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_presences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `project_presences`
--

LOCK TABLES `project_presences` WRITE;
/*!40000 ALTER TABLE `project_presences` DISABLE KEYS */;
/*!40000 ALTER TABLE `project_presences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `project_revisions`
--

DROP TABLE IF EXISTS `project_revisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_revisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `revision_number` int unsigned NOT NULL,
  `created_by` bigint unsigned NOT NULL,
  `validated` tinyint(1) NOT NULL DEFAULT '0',
  `validated_at` timestamp NULL DEFAULT NULL,
  `validated_by` bigint unsigned DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_revisions_project_id_revision_number_unique` (`project_id`,`revision_number`),
  KEY `project_revisions_created_by_foreign` (`created_by`),
  KEY `project_revisions_validated_by_foreign` (`validated_by`),
  CONSTRAINT `project_revisions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `project_revisions_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_revisions_validated_by_foreign` FOREIGN KEY (`validated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `project_revisions`
--

LOCK TABLES `project_revisions` WRITE;
/*!40000 ALTER TABLE `project_revisions` DISABLE KEYS */;
/*!40000 ALTER TABLE `project_revisions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `projects`
--

DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contractor` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `site_location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `owner_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date` date DEFAULT NULL,
  `revision` smallint unsigned NOT NULL DEFAULT '0',
  `active_revision_id` bigint unsigned DEFAULT NULL,
  `visibility` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `branch_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cover_percentage` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `value` decimal(11,2) DEFAULT NULL,
  `quote_notes` text COLLATE utf8mb4_unicode_ci,
  `internal_notes` text COLLATE utf8mb4_unicode_ci,
  `general_notes` text COLLATE utf8mb4_unicode_ci,
  `salesforce_project` tinyint(1) NOT NULL DEFAULT '0',
  `salesforce_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `last_edited_at` timestamp NULL DEFAULT NULL,
  `last_edited_by` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `projects_name_unique` (`name`),
  UNIQUE KEY `projects_reference_number_unique` (`reference_number`),
  KEY `projects_user_id_foreign` (`user_id`),
  KEY `projects_active_revision_id_foreign` (`active_revision_id`),
  KEY `projects_last_edited_by_foreign` (`last_edited_by`),
  CONSTRAINT `projects_active_revision_id_foreign` FOREIGN KEY (`active_revision_id`) REFERENCES `project_revisions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `projects_last_edited_by_foreign` FOREIGN KEY (`last_edited_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `projects_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `projects`
--

LOCK TABLES `projects` WRITE;
/*!40000 ALTER TABLE `projects` DISABLE KEYS */;
/*!40000 ALTER TABLE `projects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES ('vmKfJnIe6k87zRmxgJqIwiQmEa6lQfsY1rLU2XkA',NULL,'172.19.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJvc2EyaHhGQ1J5OU0xeEhHdkNkbkxOWmJaOGp5bnFoSmpGOFJsM3NUIiwidXJsIjp7ImludGVuZGVkIjoiaHR0cDpcL1wvbG9jYWxob3N0XC9wcm9qZWN0c1wvOVwvb3V0cHV0In0sIl9wcmV2aW91cyI6eyJ1cmwiOiJodHRwOlwvXC9sb2NhbGhvc3RcL2xvZ2luIiwicm91dGUiOiJmaWxhbWVudC5hZG1pbi5hdXRoLmxvZ2luIn0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfX0=',1782826580),('x1mtYMxF4xiI7WnoTGX7HPpn7H9khMWts1314F5R',NULL,'172.19.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJzT1NHNjdDWnJEbVpNMzVDVTMzWUFMRHNmSE9SUVZpczlXM2V3STJXIiwidXJsIjp7ImludGVuZGVkIjoiaHR0cDpcL1wvbG9jYWxob3N0XC9wcm9qZWN0c1wvMTZcL291dHB1dCJ9LCJfcHJldmlvdXMiOnsidXJsIjoiaHR0cDpcL1wvbG9jYWxob3N0XC9sb2dpbiIsInJvdXRlIjoiZmlsYW1lbnQuYWRtaW4uYXV0aC5sb2dpbiJ9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1782826618);
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'users',
  `permission_group_id` bigint unsigned DEFAULT NULL,
  `area_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `job_role` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `app_authentication_secret` text COLLATE utf8mb4_unicode_ci,
  `app_authentication_recovery_codes` text COLLATE utf8mb4_unicode_ci,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_permission_group_id_foreign` (`permission_group_id`),
  CONSTRAINT `users_permission_group_id_foreign` FOREIGN KEY (`permission_group_id`) REFERENCES `permission_groups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'laravel'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-06-30 13:50:24
