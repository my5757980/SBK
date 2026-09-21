---
feature_id: 1
feature_name: car-auction
title: SBK Car Auction Portal
version: 1.0.0
status: specified
created_date: 2026-07-28
last_updated: 2026-07-28
---

# SBK Car Auction Portal - Feature Specification

## Executive Summary

SBK Car Auction Portal is a complete PHP e-commerce platform enabling users to browse, bid on, and purchase vehicles through an online auction system. The platform provides a seamless experience for both clients (buyers) and administrators, featuring comprehensive car inventory management, real-time bidding, order processing, and customer inquiry handling.

**Core Value Proposition**: Enable global buyers to participate in car auctions online with transparent pricing, detailed vehicle information, and secure order management.

---

## Scope Definition

### In Scope
- **Car Listings & Discovery**: Browse active cars with filtering by make, model, year, and price range
- **Detailed Car Information**: View comprehensive car details including multi-image gallery, specifications, and auction sheet PDFs
- **Client Accounts**: User registration, authentication, profile management
- **Order Management**: Place orders, track order status, view order history
- **Customer Inquiries**: Send questions about vehicles, receive responses from support team
- **Admin Dashboard**: Manage inventory, process orders, oversee client database, monitor platform metrics
- **Responsive Design**: Fully functional on desktop, tablet, and mobile devices

### Out of Scope
- Real-time bidding/auction functionality (fixed price purchases only)
- Payment gateway integration (order tracking without payment processing)
- Shipping/logistics management (order status tracking only)
- International shipping (domestic only)
- Video tours or 3D car models
- Mobile native app (web responsive only)
- Multi-language support (English only)

### Assumptions
- Users have basic internet connectivity and web browser
- Car images are pre-uploaded to system
- Admin users are trusted internal staff (no role-based permissions initially)
- Database running on localhost or internal network
- PHP 7.4+ and MySQL 5.7+ available
- PDF auction sheets already created externally

---

## User Scenarios & Acceptance Criteria

### Primary User Flows

#### Scenario 1: Browse & Discover Cars
**Actor**: Potential Buyer (Guest/Registered)
**Goal**: Find a specific car matching their preferences

**Flow**:
1. User visits home page and sees car listings (12 per page)
2. User applies filters: Toyota, 2020-2022, ¥3M-¥5M
3. System displays matching cars with images and key specs
4. User clicks on specific car to view full details
5. User reads complete specifications and sees all images
6. **Acceptance**: User can filter by make/model/year/price and view results in under 3 seconds

#### Scenario 2: Register & Create Account
**Actor**: New Buyer
**Goal**: Create account to place orders and inquiries

**Flow**:
1. User clicks "Register" button
2. User fills form: Name, Email, Phone, Password
3. System validates email uniqueness and password strength
4. System creates account and auto-logs in user
5. User redirected to dashboard
6. **Acceptance**: Registration completes in under 2 minutes; validation errors clear

#### Scenario 3: Place an Order
**Actor**: Registered Buyer
**Goal**: Purchase a specific car

**Flow**:
1. Logged-in user views car details
2. User clicks "Place Order" button
3. System shows order summary with buyer info and car details
4. User confirms order and agrees to terms
5. System generates unique order number (ORD-YYYY-XXXXXX)
6. System saves order as "pending" status
7. User sees confirmation page with order details
8. **Acceptance**: Order created in under 1 second; order number unique and persistent

#### Scenario 4: Send Inquiry About Car
**Actor**: Registered Buyer
**Goal**: Ask question about vehicle before purchasing

**Flow**:
1. User on car details page enters inquiry message
2. System validates message not empty
3. System creates inquiry ticket with unique number (INQ-YYYY-XXXXXX)
4. System sends to admin dashboard
5. User sees "Inquiry sent" confirmation
6. Admin reviews inquiry and provides response
7. User sees response in dashboard
8. **Acceptance**: Inquiry created in under 1 second; admin can respond within platform

#### Scenario 5: Admin Manages Orders
**Actor**: Admin/Staff
**Goal**: Process customer orders and update status

**Flow**:
1. Admin logs in with credentials (admin/admin123)
2. Admin accesses "Orders" section
3. Admin sees all orders with status filter options
4. Admin selects order and updates status: pending → approved → confirmed → paid → shipped → delivered
5. System persists status change immediately
6. **Acceptance**: Status updates in real-time; all status transitions tracked

#### Scenario 6: Admin Views Dashboard
**Actor**: Admin
**Goal**: Monitor platform health and key metrics

**Flow**:
1. Admin logs in and views dashboard
2. Dashboard displays: total cars, active cars, total clients, total orders, total revenue
3. Dashboard shows recent orders table (last 5 orders)
4. Admin can click to drill into details
5. **Acceptance**: Dashboard loads in under 2 seconds; metrics are current

---

## Functional Requirements

### FR-1: Car Listing & Discovery
- **FR-1.1**: System displays active cars in grid layout (12 cars per page)
- **FR-1.2**: System provides search filters for make, model, year range, price range
- **FR-1.3**: System applies all active filters simultaneously (AND logic)
- **FR-1.4**: System shows car image, make/model, year, mileage, price, and status for each listing
- **FR-1.5**: System supports pagination (Previous/Next/Page numbers)
- **FR-1.6**: System displays "No results" message when filters return zero cars
- **Acceptance**: User can filter 500-car database and see results in <3 seconds; pagination works smoothly

### FR-2: Car Details & Gallery
- **FR-2.1**: System displays comprehensive car details: ID, lot number, make, model, year, mileage, price, status
- **FR-2.2**: System shows main image with thumbnail gallery below
- **FR-2.3**: System allows clicking thumbnails to update main image
- **FR-2.4**: System provides link to auction sheet PDF (if available)
- **FR-2.5**: System displays related cars (by same make) below details
- **Acceptance**: All images load in <2 seconds; gallery navigation is smooth; PDF link works

### FR-3: Client Registration
- **FR-3.1**: System accepts name, email, phone, password, password confirmation
- **FR-3.2**: System validates email format and uniqueness (rejects duplicates)
- **FR-3.3**: System enforces password minimum 6 characters
- **FR-3.4**: System validates passwords match (confirmation check)
- **FR-3.5**: System hashes password using bcrypt before storage
- **FR-3.6**: System auto-logs in user after successful registration
- **FR-3.7**: System displays clear validation error messages
- **Acceptance**: Invalid emails rejected; duplicate emails rejected; weak passwords rejected; valid registration completes in <2 seconds

### FR-4: Client Authentication
- **FR-4.1**: System accepts email and password for login
- **FR-4.2**: System validates credentials against stored hash
- **FR-4.3**: System creates secure session on successful login
- **FR-4.4**: System redirects to dashboard after login
- **FR-4.5**: System displays "Invalid email or password" on auth failure (generic message)
- **FR-4.6**: System logs out user and destroys session on logout
- **Acceptance**: Valid credentials grant access; invalid credentials denied; session persists across page reloads

### FR-5: Order Management
- **FR-5.1**: System shows order confirmation page before placing order
- **FR-5.2**: System requires user agreement to terms (checkbox)
- **FR-5.3**: System generates unique order number format: ORD-YYYY-[6-digit random]
- **FR-5.4**: System captures car details snapshot as JSON at purchase time
- **FR-5.5**: System sets initial order status to "pending"
- **FR-5.6**: System displays order confirmation with order number and details
- **FR-5.7**: System allows only logged-in users to place orders
- **Acceptance**: Order created atomically; order number never duplicated; snapshot captures accurate data; terms checkbox required

### FR-6: Order History & Tracking
- **FR-6.1**: System displays client's orders in dashboard
- **FR-6.2**: System shows order number, car details, amount, status, and date for each order
- **FR-6.3**: System allows filtering/sorting by status and date
- **FR-6.4**: System displays status badge with color coding: pending (blue), approved (green), paid (green), delivered (green), cancelled (red)
- **Acceptance**: Client can view all their orders; statuses are current and accurate; filtering works correctly

### FR-7: Inquiry System
- **FR-7.1**: System displays inquiry form on car details page
- **FR-7.2**: System requires login to send inquiry
- **FR-7.3**: System validates inquiry message is not empty
- **FR-7.4**: System generates unique inquiry number: INQ-YYYY-[6-digit random]
- **FR-7.5**: System captures: inquiry message, car details, client info
- **FR-7.6**: System displays success message "Inquiry sent successfully"
- **FR-7.7**: System stores inquiry status as "open" initially
- **Acceptance**: Empty inquiries rejected; inquiries created in <1 second; numbers never duplicate

### FR-8: Admin Dashboard
- **FR-8.1**: System displays key metrics: total cars, active cars, total clients, total orders, revenue
- **FR-8.2**: System displays recent orders table (last 5 orders)
- **FR-8.3**: System calculates revenue from orders with status in: confirmed, paid, shipped, delivered
- **FR-8.4**: System requires admin login to access
- **FR-8.5**: System uses hardcoded credentials: admin/admin123
- **Acceptance**: Metrics updated in real-time; no caching; dashboard loads in <2 seconds

### FR-9: Admin Car Management
- **FR-9.1**: System displays all cars with search by make/model/car_id
- **FR-9.2**: System allows admin to delete cars (with confirmation)
- **FR-9.3**: System removes car from all orders/inquiries on delete (or prevents deletion if referenced)
- **FR-9.4**: System displays success/error message after delete action
- **Acceptance**: Search filters cars in <2 seconds; delete completes in <1 second; confirmation prevents accidental delete

### FR-10: Admin Order Management
- **FR-10.1**: System displays all orders with columns: order number, client, car, amount, status, date
- **FR-10.2**: System provides status dropdown for each order (pending → approved → confirmed → paid → shipped → delivered → cancelled)
- **FR-10.3**: System updates status in real-time when dropdown changes
- **FR-10.4**: System displays client email and contact details
- **Acceptance**: All status transitions work; no page reload needed for update; updates persist

### FR-11: Admin Client Management
- **FR-11.1**: System displays all registered clients with: name, email, phone, location, active/inactive status
- **FR-11.2**: System shows client registration date
- **FR-11.3**: System displays client count summary
- **FR-11.4**: System allows viewing but not editing client details (read-only view)
- **Acceptance**: All client data displays accurately; client count is correct

### FR-12: User Interface & Responsiveness
- **FR-12.1**: System displays navigation bar on all pages
- **FR-12.2**: System shows "Login/Register" links for anonymous users
- **FR-12.3**: System shows "Dashboard/Logout" for authenticated users
- **FR-12.4**: System is fully responsive (desktop, tablet, mobile)
- **FR-12.5**: System uses consistent blue/white color scheme throughout
- **FR-12.6**: System displays status badges with color coding (active=green, sold=red, reserved=orange)
- **Acceptance**: All pages load in <3 seconds; all forms are touch-friendly; no horizontal scrolling on mobile

### FR-13: Incremental Auto-Sync Data Synchronization
- **FR-13.1**: System runs background auto-sync service every 5 minutes (300 seconds)
- **FR-13.2**: System fetches only new car arrivals from nikkyocars.ajes.com/japan (not full 58k catalog)
- **FR-13.3**: System fetches only recently updated cars (price changes, status changes)
- **FR-13.4**: System logs into nikkyocars.ajes.com/japan with provided credentials
- **FR-13.5**: System detects car changes: new listings, price updates (¥X → ¥Y), status changes (available → sold)
- **FR-13.6**: System updates MySQL database using INSERT for new cars, UPDATE for changed cars
- **FR-13.7**: System maintains `created_at` (original insert time) and `last_updated` (sync timestamp)
- **FR-13.8**: System logs all sync activity to sync_log.txt with timestamps: [YYYY-MM-DD HH:MM:SS]
- **FR-13.9**: System recovers gracefully from network errors, database connection loss, and scraping failures
- **FR-13.10**: System uses prepared statements for all database queries (SQL injection prevention)
- **FR-13.11**: System never logs credentials or passwords
- **FR-13.12**: System runs continuously in background (24/7 infinite loop)
- **Acceptance**: Sync completes every 5 minutes; only changed cars fetched; database remains consistent; sync_log.txt shows complete operation history; no data loss on errors

---

## Success Criteria

### Performance Metrics
- **Page Load Time**: All pages load in under 3 seconds (average)
- **Search Speed**: Car filter results display in under 2 seconds
- **Database Queries**: All queries complete in under 500ms
- **Concurrent Users**: System supports 100 concurrent users without degradation

### User Experience Metrics
- **Task Completion**: Users complete registration in under 2 minutes
- **Order Placement**: Users complete order placement in under 3 minutes
- **Admin Efficiency**: Admin can update 50 order statuses in under 5 minutes
- **Error Clarity**: All validation errors are clear and actionable

### Data Integrity Metrics
- **No Data Loss**: All orders, inquiries, and client data persist correctly
- **Data Uniqueness**: Order/inquiry numbers never duplicate across system lifetime
- **Data Accuracy**: Car details snapshot matches display data at time of purchase
- **Data Consistency**: Same car data shown to all users simultaneously

### Functional Coverage
- **Feature Completeness**: All 13 functional requirement categories fully implemented (includes auto-sync)
- **No Critical Bugs**: Zero critical bugs in user-facing and background functionality
- **Browser Compatibility**: Works on Chrome, Firefox, Safari, Edge (latest versions)
- **Mobile Usability**: All features accessible and functional on mobile devices
- **Data Freshness**: Car inventory synced every 5 minutes; never >5 min stale

---

## Key Entities & Data Model

### Primary Entities
1. **Car**
   - Unique ID, Car ID, Lot Number
   - Make, Model, Year, Mileage
   - Price, Currency, Status
   - Images (JSON array), Auction Sheet (PDF URL)

2. **Client**
   - ID, Name, Email (unique), Phone
   - Address, City, State, Country
   - Password (hashed), Verification status
   - Registration date

3. **Order**
   - ID, Order Number (unique), Client ID
   - Car ID, Car Details (JSON snapshot)
   - Amount, Currency, Status
   - Timestamps: order date, payment date, shipping date, delivery date

4. **Inquiry**
   - ID, Inquiry Number (unique), Client ID
   - Car ID, Message, Response
   - Status, Priority, Assigned staff
   - Timestamps: created, responded

---

## Technical Constraints (Non-Functional)

### Security
- Passwords hashed with bcrypt
- SQL injection prevention via prepared statements
- XSS prevention via HTML entity encoding
- Session-based authentication (PHP sessions)
- No hardcoded secrets in code (credentials in config files)

### Compatibility
- PHP 7.4+ required
- MySQL 5.7+ or MariaDB required
- Modern browsers (Chrome 90+, Firefox 88+, Safari 14+, Edge 90+)
- Responsive design for 320px to 2560px screen widths

### Limitations
- Single admin user (no role-based access control)
- No real-time updates (page refresh required)
- No payment processing (orders only)
- No file uploads (admin manually manages images)
- No email notifications initially

---

## Assumptions & Dependencies

### Assumptions
- Administrators are trusted internal staff (no permission restrictions)
- Car images pre-hosted and URLs in database
- PDF auction sheets already generated externally
- MySQL/MariaDB runs on localhost or accessible network
- No SSL/HTTPS required for initial version
- Users have JavaScript enabled in browsers
- No extreme scale requirements (starting with <1000 cars)

### Dependencies
- PHP 7.4+ with MySQLi extension
- MySQL 5.7+ or MariaDB 10.3+
- Web server (Apache, Nginx, or PHP built-in)
- No external APIs or third-party integrations required

### Risks
- **Port 80 Conflict**: May conflict with other services (mitigation: configurable port)
- **Data Backup**: No automated backup system (mitigation: manual database dumps)
- **Scalability**: Single-server deployment limits to ~10K users (mitigation: future database optimization)
- **Security**: Hardcoded admin password (mitigation: change in config before production)

---

## Acceptance Testing Checklist

- [ ] User can register new account with valid data
- [ ] Registration rejects invalid email and duplicate emails
- [ ] User can login with correct credentials
- [ ] Login rejects invalid credentials
- [ ] User can browse cars with pagination
- [ ] Search filters work individually and combined
- [ ] Car details page displays all information and images
- [ ] User can place order and receive order number
- [ ] User can send inquiry and see confirmation
- [ ] Admin can login with admin/admin123
- [ ] Admin dashboard shows correct metrics
- [ ] Admin can update order status
- [ ] Admin can view and delete cars
- [ ] Admin can view all clients
- [ ] All pages responsive on mobile (375px width)
- [ ] No console JavaScript errors
- [ ] All forms validate properly
- [ ] Session persists across page reloads
- [ ] Logout clears session

---

## Next Steps

This specification is complete and ready for:
1. **Architecture Planning** (`/sp.plan`) - Design system architecture
2. **Task Breakdown** (`/sp.tasks`) - Create actionable development tasks
3. **Implementation** (`/sp.implement`) - Build according to spec and plan

---

**Status**: ✅ Specification Complete - Ready for Planning Phase
