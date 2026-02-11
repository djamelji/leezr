# Presets UI — Apps Views

> Composants spécifiques aux modules applicatifs.
> Source : `resources/ui/presets/apps/`

## Par module

### E-commerce (14 views)
| View | Type | Props |
|------|------|-------|
| CustomerBioPanel | Profile card | customerData |
| CustomerOrderTable | DataTable | - (API) |
| CustomerTabOverview | Stats | customerData |
| CustomerTabAddressAndBilling | Form | customerData |
| CustomerTabNotification | Settings | customerData |
| CustomerTabSecurity | Form | customerData |
| ECommerceAddCategoryDrawer | Drawer form | isDrawerOpen |
| ECommerceAddCustomerDrawer | Drawer form | isDrawerOpen |
| SettingsStoreDetails | Form | - |
| SettingsPayment | Form | - |
| SettingsCheckout | Form | - |
| SettingsShippingAndDelivery | Form | - |
| SettingsLocations | CRUD | - |
| SettingsNotifications | Toggles | - |

### User Management (8 views)
| View | Type | Props |
|------|------|-------|
| AddNewUserDrawer | Drawer form | isDrawerOpen |
| UserBioPanel | Profile card | userData |
| UserInvoiceTable | DataTable | userId |
| UserTabAccount | Form | userData |
| UserTabBillingsPlans | Display | userData |
| UserTabConnections | List | userData |
| UserTabNotifications | Toggles | userData |
| UserTabSecurity | Form | userData |

### Invoice (4 views)
| View | Type | Props |
|------|------|-------|
| InvoiceEditable | Form complexe | invoiceData |
| InvoiceProductEdit | Sub-form | index, product |
| InvoiceAddPaymentDrawer | Drawer | isDrawerOpen |
| InvoiceSendInvoiceDrawer | Drawer | isDrawerOpen |

### Chat (5 views)
ChatActiveChatUserProfileSidebarContent, ChatContact, ChatLeftSidebarContent, ChatLog, ChatUserProfileSidebarContent

### Email (3 views)
ComposeDialog, EmailLeftSidebarContent, EmailView

### Kanban (4 views)
KanbanBoard, KanbanBoardEditDrawer, KanbanCard, KanbanItems

### Calendar (1 view)
CalendarEventHandler

### Logistics (7 views)
CardStatistics, DeliveryExpectations, DeliveryPerformance, OrderByCountries, OverviewTable, ShipmentStatistics, VehicleOverview

### Academy (7 views)
AssignmentProgress, PopularInstructors, TopCourses, CourseTable, MyCourses, TopicInterested, UpcomingWebinar

### Roles (2 views)
RoleCards, UserList

## Extraits

_Aucun pour l'instant._
