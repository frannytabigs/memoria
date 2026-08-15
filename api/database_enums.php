<?php

require_once 'notallowed.php';
// --- ENUM CONSTANTS ---
// Manually update these if the database schema ever changes
const ROLE_ADMIN = 'Administrator';
const ROLE_OFFICE = 'Office Staff';
const ROLE_GROUNDS = 'Grounds Staff'; // Default Role I guess

const STATUS_VERIFIED = 'Verified';
const STATUS_UNVERIFIED = 'Unverified'; // Default Status I guess

const ALLOWED_ROLES = [ROLE_ADMIN, ROLE_OFFICE, ROLE_GROUNDS];
const ALLOWED_STATUSES = [STATUS_VERIFIED, STATUS_UNVERIFIED];
?>