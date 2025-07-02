# IT Help Desk Ticketing System

## Project Overview
Comprehensive IT help desk and ticketing system built with Laravel framework.

**Timeline**: June 2014 - August 2014  
**Technology Stack**: PHP 5.6, Laravel 4.2, MySQL, Bootstrap 3, jQuery  
**Role**: IT Administrator - Etech Eritrea PLC

## Features
- Ticket management and tracking
- SLA monitoring and alerts
- Knowledge base system
- Asset tracking integration
- Email notification system
- Mobile-responsive interface
- Reporting and analytics
- LDAP authentication

## Architecture
- **Laravel Framework**: Core application structure
- **MySQL Database**: Data storage and management
- **Bootstrap UI**: Responsive web interface
- **Email Integration**: SMTP/IMAP support
- **LDAP Auth**: Active Directory integration

## Installation
```bash
# Clone and setup
composer install
php artisan migrate
php artisan db:seed

# Configure environment
cp .env.example .env
php artisan key:generate
```