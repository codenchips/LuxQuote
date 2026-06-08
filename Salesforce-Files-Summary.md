===========================================================================
SALESFORCE FILE UPLOAD VIA REST API & PHP CHEATSHEET
===========================================================================

1. UNDERSTANDING THE ARCHITECTURE
---------------------------------------------------------------------------
Salesforce does not save files as attachments on individual record tables. 
It splits files across three related objects:
* ContentVersion: The binary data payload, title, and file extension.
* ContentDocument: The parent document envelope (tracks history & versions).
* ContentDocumentLink: The junction table associating a ContentDocument to a 
  Target Record (Opportunity, Account, Case, etc.).

2. THE TWO ROUTING STRATEGIES
---------------------------------------------------------------------------
STRATEGY A: Direct-Link on Upload (Simplest & Best Practice)
* Action: Include the Opportunity ID inside the 'FirstPublishLocationId' 
  metadata field during your initial upload to ContentVersion.
* Outcome: Salesforce automatically creates the ContentDocumentLink behind 
  the scenes. One API call, job done.

STRATEGY B: Manual Multi-Record Linkage (For complex logic)
* Action: Upload the file -> Retrieve ContentVersion ID -> Run SOQL to 
  find the ContentDocumentId -> Insert a row into ContentDocumentLink.
* Outcome: Allows you to connect one single uploaded file to multiple 
  records sequentially.

3. THE CORE STEP-BY-STEP WORKFLOW
---------------------------------------------------------------------------
Step 1: Obtain a valid OAuth2 Access Token via your Connected App.
Step 2: Format your payload as a multipart/form-data request.
        - Part 1: Content-Type: application/json (Contains metadata like Title 
                  and PathOnClient).
        - Part 2: Content-Type: application/octet-stream (Contains raw binary file data).
Step 3: POST to the endpoint: /services/data/v60.0/sobjects/ContentVersion
Step 4: Catch the ContentVersion ID string from the server JSON response.
Step 5: (If Strategy B) Query ContentVersion to extract ContentDocumentId, 
        then POST to /sobjects/ContentDocumentLink.

4. ESSENTIAL PAYLOAD ATTRIBUTES REFERENCE
---------------------------------------------------------------------------
* VersionData:             The literal un-encoded binary file array.
* PathOnClient:            The source filename (e.g., 'invoice.pdf'). 
                           Determines file parsing/extensions in Salesforce.
* Title:                   The clean presentation string seen by CRM users.
* FirstPublishLocationId:  The target destination record ID (e.g., Opportunity ID '006...').
* ContentDocumentId:       The specific document parent wrapper reference.
* LinkedEntityId:          The record target lookup used in junction tables.
* ShareType:               Controls viewer access levels ('V' = Viewer, 'C' = Collaborator).
===========================================================================
