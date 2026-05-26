-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: iephotoo_booking
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `bookings`
--

DROP TABLE IF EXISTS `bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bookings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_type` enum('equipment','studio') NOT NULL,
  `item_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `guest_name` varchar(100) DEFAULT NULL,
  `guest_email` varchar(100) DEFAULT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime NOT NULL,
  `form_image_path` varchar(255) DEFAULT NULL,
  `usage_reason` text DEFAULT NULL,
  `usage_type` varchar(100) DEFAULT NULL,
  `status` enum('pending','approved','rejected','returned','cancelled','pending_return') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `consent_token` varchar(64) DEFAULT NULL,
  `consent_responded_at` timestamp NULL DEFAULT NULL,
  `responsible_user_id` int(11) DEFAULT NULL,
  `return_image_path` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bookings`
--

LOCK TABLES `bookings` WRITE;
/*!40000 ALTER TABLE `bookings` DISABLE KEYS */;
INSERT INTO `bookings` VALUES (12,'equipment',1,4,NULL,NULL,'2026-05-17 10:33:41','2026-05-18 10:33:41',NULL,'????????????????????????????????????????????????',NULL,'returned','2026-05-16 03:33:41',NULL,NULL,NULL,NULL),(13,'equipment',2,3,NULL,NULL,'2026-05-19 10:33:41','2026-05-20 10:33:41',NULL,'???????????? MV ??????????????????',NULL,'approved','2026-05-18 03:33:41',NULL,NULL,NULL,NULL),(14,'equipment',3,2,NULL,NULL,'2026-05-23 10:33:41','2026-05-24 10:33:41',NULL,'?????????????????????????????????????????????',NULL,'pending','2026-05-21 03:33:41',NULL,NULL,NULL,NULL),(15,'equipment',5,4,NULL,NULL,'2026-05-25 10:33:41','2026-05-26 10:33:41',NULL,'??????????????????????????????',NULL,'approved','2026-05-22 03:33:41','6e6a7d483b7ad1830e54bfeebe36a28cf3a96dd39ef67071eca6c3c152812efb',NULL,NULL,NULL),(16,'equipment',7,3,NULL,NULL,'2026-05-21 10:33:41','2026-05-23 10:33:41',NULL,'??????????????? lens ????????????',NULL,'approved','2026-05-20 03:33:41',NULL,NULL,NULL,NULL),(17,'equipment',2,2,NULL,NULL,'2026-05-14 10:33:41','2026-05-15 10:33:41',NULL,'???????????????????????????????????????',NULL,'rejected','2026-05-13 03:33:41',NULL,NULL,NULL,NULL),(18,'studio',1,4,NULL,NULL,'2026-05-24 10:33:41','2026-05-24 13:33:41',NULL,'???????????????????????????????????????',NULL,'pending','2026-05-22 03:33:41',NULL,NULL,NULL,NULL),(19,'studio',2,2,NULL,NULL,'2026-05-26 10:33:41','2026-05-26 12:33:41',NULL,'Concept Dark Theme',NULL,'approved','2026-05-21 03:33:41',NULL,NULL,NULL,NULL),(20,'studio',1,3,NULL,NULL,'2026-05-20 10:33:41','2026-05-20 12:33:41',NULL,'???????????? Product',NULL,'returned','2026-05-19 03:33:41',NULL,NULL,NULL,NULL),(21,'studio',1,NULL,'???????????????????????? ???????????????','guest@example.com','2026-05-28 10:33:41','2026-05-28 12:33:41',NULL,'?????????????????????????????????????????????',NULL,'pending','2026-05-22 03:33:41',NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `bookings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `email_consents`
--

DROP TABLE IF EXISTS `email_consents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_consents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `action` enum('approve','reject') NOT NULL,
  `responded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `booking_id` (`booking_id`),
  CONSTRAINT `email_consents_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `email_consents`
--

LOCK TABLES `email_consents` WRITE;
/*!40000 ALTER TABLE `email_consents` DISABLE KEYS */;
/*!40000 ALTER TABLE `email_consents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `equipments`
--

DROP TABLE IF EXISTS `equipments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `equipments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `type` enum('camera','lens','accessory') NOT NULL,
  `status` enum('available','borrowed','maintenance') NOT NULL DEFAULT 'available',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `equipments`
--

LOCK TABLES `equipments` WRITE;
/*!40000 ALTER TABLE `equipments` DISABLE KEYS */;
INSERT INTO `equipments` VALUES (1,'a7','camera','available'),(2,'หี','accessory','available'),(3,'Sony A7 III','camera','available'),(4,'Canon EOS R5','camera','available'),(5,'Nikon Z6 II','camera','borrowed'),(6,'DJI Ronin-S','accessory','available'),(7,'Godox AD200 Pro','accessory','available'),(8,'Tripod Manfrotto','accessory','available'),(9,'Lens 50mm f/1.8','lens','available'),(10,'Lens 24-70mm f/2.8','lens','available');
/*!40000 ALTER TABLE `equipments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `feed_likes`
--

DROP TABLE IF EXISTS `feed_likes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `feed_likes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `feed_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `feed_id` (`feed_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `feed_likes_ibfk_1` FOREIGN KEY (`feed_id`) REFERENCES `feeds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `feed_likes_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `feed_likes`
--

LOCK TABLES `feed_likes` WRITE;
/*!40000 ALTER TABLE `feed_likes` DISABLE KEYS */;
/*!40000 ALTER TABLE `feed_likes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `feeds`
--

DROP TABLE IF EXISTS `feeds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `feeds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `booking_id` (`booking_id`),
  CONSTRAINT `feeds_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `feeds`
--

LOCK TABLES `feeds` WRITE;
/*!40000 ALTER TABLE `feeds` DISABLE KEYS */;
INSERT INTO `feeds` VALUES (13,1,'???? ??????????????? ???????????? ????????? Sony A7 III ?????????????????? ??? ????????????????????????????????????????????????','2026-05-17 03:33:41'),(14,2,'???? ???????????????????????? ????????????????????? ??????????????????????????????????????? Canon EOS R5 ??? ???????????? MV','2026-05-19 03:33:41'),(15,5,'???? ???????????????????????? ????????????????????? ???????????????????????? Lens 50mm f/1.8','2026-05-21 03:33:41'),(16,8,'???? ???????????? ???????????????????????? ????????? Studio B ?????????????????? ??? Dark Theme','2026-05-21 03:33:41'),(17,9,'??? ???????????????????????? ?????????????????????????????? Studio A ???????????????????????????','2026-05-20 03:33:41'),(18,15,'อัปเดต: การจอง #15 Nikon Z6 II — ได้รับการอนุมัติ ✅','2026-05-23 16:45:45');
/*!40000 ALTER TABLE `feeds` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `studios`
--

DROP TABLE IF EXISTS `studios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `studios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `studios`
--

LOCK TABLES `studios` WRITE;
/*!40000 ALTER TABLE `studios` DISABLE KEYS */;
INSERT INTO `studios` VALUES (1,'Studio 1','open'),(2,'Studio 2','open'),(3,'Studio A - White Cyc',''),(4,'Studio B - Dark Theme',''),(5,'Studio C - Outdoor Look','');
/*!40000 ALTER TABLE `studios` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tasks`
--

DROP TABLE IF EXISTS `tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `assigned_by` int(11) NOT NULL,
  `assigned_to` int(11) NOT NULL,
  `booking_id` int(11) DEFAULT NULL,
  `status` enum('pending','in_progress','completed','cancelled') DEFAULT 'pending',
  `due_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `assigned_by` (`assigned_by`),
  KEY `assigned_to` (`assigned_to`),
  CONSTRAINT `tasks_ibfk_1` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`),
  CONSTRAINT `tasks_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tasks`
--

LOCK TABLES `tasks` WRITE;
/*!40000 ALTER TABLE `tasks` DISABLE KEYS */;
INSERT INTO `tasks` VALUES (1,'????????????????????????????????????????????????????????????','??????????????????????????????????????????????????????????????????????????????',1,4,NULL,'in_progress','2026-05-29 10:33:41','2026-05-21 03:33:41'),(2,'??????????????????????????????????????????????????????','????????????????????????????????????????????? album ?????? drive',1,3,NULL,'in_progress','2026-05-25 10:33:41','2026-05-20 03:33:41'),(3,'?????? catalog ?????????????????????????????????','????????????????????????????????????????????????????????????????????????????????????????????????????????????',1,2,NULL,'pending','2026-06-05 10:33:41','2026-05-22 03:33:41'),(4,'????????????????????????????????????????????????????????????','???????????????????????????????????????????????? Canon EOS R5',1,3,NULL,'completed','2026-05-21 10:33:41','2026-05-18 03:33:41');
/*!40000 ALTER TABLE `tasks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `urgent_contacts`
--

DROP TABLE IF EXISTS `urgent_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `urgent_contacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `status` enum('calling','acknowledged','resolved') NOT NULL DEFAULT 'calling',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `sender_id` (`sender_id`),
  KEY `receiver_id` (`receiver_id`),
  CONSTRAINT `urgent_contacts_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `urgent_contacts_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `urgent_contacts`
--

LOCK TABLES `urgent_contacts` WRITE;
/*!40000 ALTER TABLE `urgent_contacts` DISABLE KEYS */;
INSERT INTO `urgent_contacts` VALUES (1,2,1,'resolved','2026-03-22 15:21:11'),(2,3,2,'resolved','2026-03-23 03:01:37'),(3,2,3,'resolved','2026-03-23 04:10:43'),(4,4,2,'resolved','2026-05-13 06:22:19');
/*!40000 ALTER TABLE `urgent_contacts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(8) DEFAULT NULL,
  `first_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `role` enum('admin','member','guest') NOT NULL DEFAULT 'member',
  `profile_image` varchar(255) DEFAULT 'default.png',
  `contact_status` enum('available','busy','offline') NOT NULL DEFAULT 'offline',
  `profile_completed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `email_verify_token` varchar(64) DEFAULT NULL,
  `email_verify_sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `student_id` (`student_id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'68030263','?????????????????????','?????????????????????','68030263@kmitl.ac.th','$2y$10$iGah0btFmnr4PK9aTfFTseoth1JCN0J4wbz1nn2M/A1rk96KXuJZ6','0830334694','admin','1_1774178934.png','offline',1,'2026-03-22 11:28:34',1,NULL,NULL),(2,'68030266','????????????','????????????????????????','68030266@kmitl.ac.th','$2y$10$iGah0btFmnr4PK9aTfFTseoth1JCN0J4wbz1nn2M/A1rk96KXuJZ6','0830334694','member','default.png','offline',1,'2026-03-22 11:58:17',1,NULL,NULL),(3,'68030262','????????????????????????','?????????????????????','68030262@kmitl.ac.th','$2y$10$iGah0btFmnr4PK9aTfFTseoth1JCN0J4wbz1nn2M/A1rk96KXuJZ6','0632212946','member','3_1774234794.jpeg','offline',1,'2026-03-23 02:59:25',1,NULL,NULL),(4,'68030260','???????????????','????????????','68030260@kmitl.ac.th','$2y$10$iGah0btFmnr4PK9aTfFTseoth1JCN0J4wbz1nn2M/A1rk96KXuJZ6','0830334694','member','default.png','offline',1,'2026-05-13 06:11:12',1,NULL,NULL),(5,'68030271',NULL,NULL,'68030271@kmitl.ac.th','$2y$10$bZMISyUuC.51g0wJvy4FAewoyyCufExfG6CIk28K9t.bXyufmaxRm','0931931452','admin','default.png','offline',1,'2026-05-21 17:34:14',1,NULL,NULL),(6,'68030129','นภัสสร','คำปัน','68030129@kmitl.ac.th','$2y$10$dj65jXFoenPmyRaXsRm/pOSenmKRowowfX89Sc9BMHILkjxTaDeTO','0969545290','admin','6_1779553118.jpg','offline',1,'2026-05-23 16:18:07',1,NULL,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-05-25 11:54:49
