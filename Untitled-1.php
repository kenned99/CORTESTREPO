OBJECT Codeunit 52000 Book Item Handler
{
  OBJECT-PROPERTIES
  {
    Date=06-12-18;
    Time=11:31:06;
    Modified=Yes;
    Version List=Bog;
  }
  PROPERTIES
  {
    OnRun=BEGIN
          END;

  }
  CODE
  {
    VAR
      Text0001@1160810000 : TextConst 'DAN=Ordre status skal v‘re †ben;ENU=The order status has to be open';
      Text0002@1160810001 : TextConst 'DAN=Bogen er reserveret;ENU=The book is reserved';
      Text0003@1160810002 : TextConst 'DAN=Documentet er i forvejen en udlejning;ENU=The document has a document type of rent';

    PROCEDURE IsFirstLine@1160810004(VAR DocumentLine_par@1160810000 : Record 52004) : Boolean;
    VAR
      DocumentLine_loc@1160810001 : Record 52004;
    BEGIN
      DocumentLine_loc.RESET;
      DocumentLine_loc.SETRANGE("Document No.", DocumentLine_par."Document No.");
      IF DocumentLine_loc.ISEMPTY THEN
        EXIT(TRUE)
      ELSE
        EXIT(FALSE);
    END;

    PROCEDURE AddToAvailable@1160810008(VAR BookItemTemp_par@1160810001 : TEMPORARY Record 52013;BookItem_par@1160810000 : Record 52012;DocumentLine_par@1160810002 : Record 52004);
    VAR
      DocumentLine_loc@1160810003 : Record 52004;
    BEGIN
      BookItemTemp_par.INIT;
      BookItemTemp_par."No." := BookItem_par."No.";
      BookItemTemp_par."Book Title" := BookItem_par."Book Title";

      DocumentLine_loc.RESET;
      DocumentLine_loc.SETRANGE("Document No.", DocumentLine_par."Document No.");
      DocumentLine_loc.SETRANGE("Item No.", BookItem_par."No.");
      IF DocumentLine_loc.FINDFIRST THEN
        BookItemTemp_par.Select := TRUE;
      IF BookItemTemp_par.INSERT THEN;
    END;

    PROCEDURE AddToSubform@1160810000(VAR BookItemTemp_par@1160810002 : TEMPORARY Record 52013;BookItem_par@1160810001 : Record 52012;VAR Rec@1160810003 : Record 52004);
    VAR
      Position_loc@1160810007 : Integer;
      Length_loc@1160810006 : Integer;
      NewLineString_loc@1160810005 : Text;
      NewLineNo_loc@1160810004 : Integer;
      DocumentLine_loc@1160810000 : Record 52004;
    BEGIN
      DocumentLine_loc.INIT;
      DocumentLine_loc.Matrix := Rec."Line No."; //S‘tter line no lig matriks for at have et bindeled mellem varer og matriks
      DocumentLine_loc."Document No." := Rec."Document No.";
      DocumentLine_loc.Type := DocumentLine_loc.Type::Item; //S‘tter type til vare
      DocumentLine_loc."Item No." := BookItemTemp_par."No.";

      DocumentLine_loc."Book No." := Rec."Book No.";

      Position_loc := STRPOS(BookItemTemp_par."No.", '-'); //T‘ller hvilken position "-" er i BookItemTemp_loc."No."
      EVALUATE(NewLineNo_loc, COPYSTR(BookItemTemp_par."No.", Position_loc + 1, 20)); //Kopier tallet efter "-", ligger den over i NewLineNo og konventere det til en int
      DocumentLine_loc."Line No." := Rec."Line No." + NewLineNo_loc;
      DocumentLine_loc."Book Title" := BookItemTemp_par."Book Title"; //Inds‘tter bogens titel til vare
      DocumentLine_loc."Line Quantity" := 1;

      //Beregn rabat
      DocumentLine_loc.CALCFIELDS("Book Customer", "Price (LCY)");
      DocumentLine_loc."Line Discount" := CalculateDiscount(DocumentLine_loc."Book No.", DocumentLine_loc."Book Customer");
      DocumentLine_loc."Line amount"   := (DocumentLine_loc."Price (LCY)" * DocumentLine_loc."Line Quantity") - DocumentLine_loc."Line Discount";

      IF DocumentLine_loc.INSERT(TRUE) THEN;
    END;

    PROCEDURE DocumentLineOnValidate@1160810001(VAR Rec@1160810000 : Record 52004);
    VAR
      Book_loc@1160810012 : Record 52002;
      DocumentHeader_loc@1160810011 : Record 52003;
      DocumentLine_loc@1160810010 : Record 52004;
      BookItem_loc@1160810009 : Record 52012;
      BookItemTemp_loc@1160810008 : TEMPORARY Record 52013;
      BookItemHandler_loc@1160810001 : Codeunit 52000;
      Reserved_loc@1160810002 : Record 52014;
    BEGIN
      //Udfylder bogens titel
      Book_loc.GET(Rec."Book No.");
      Rec."Book Title" := Book_loc.Title;

      // Udfylder linjer
      // ---------------

      // F›rste linje p† ordre
      IF IsFirstLine(Rec) THEN
        Rec."Line No." := 10000;

      // Inds‘t ny matrix
      IF Rec."Line No." = 0 THEN BEGIN
        DocumentLine_loc.RESET;
        DocumentLine_loc.SETRANGE("Document No.", Rec."Document No.");
        DocumentLine_loc.SETRANGE(Type, Rec.Type::Matrix);
        IF DocumentLine_loc.FINDLAST THEN
          Rec."Line No." := DocumentLine_loc."Line No." + 10000;  //Ligger 10k til den sidste Matrix
      END;

      //Tilf›jer data til temp tabel
      BookItem_loc.RESET;
      BookItem_loc.SETRANGE("Book No.", Rec."Book No.");
      BookItem_loc.SETRANGE(Rented, FALSE);
      IF BookItem_loc.FINDSET THEN
        REPEAT
          IF NOT IsReserved(BookItem_loc, Rec) THEN //Hvis ikke reserveret k›r nedenst†ende funktion
            BookItemHandler_loc.AddToAvailable(BookItemTemp_loc, BookItem_loc, Rec);
        UNTIL BookItem_loc.NEXT = 0;

      //Viser b›ger der er p† linjen, med et flueben i temp tabellen
      DocumentLine_loc.RESET;
      DocumentLine_loc.SETRANGE("Document No.", Rec."Document No.");
      DocumentLine_loc.SETRANGE(Type, DocumentLine_loc.Type::Item);
      DocumentLine_loc.SETRANGE("Book No.", Rec."Book No.");
      IF DocumentLine_loc.FINDSET THEN
        REPEAT
          IF BookItem_loc.GET(DocumentLine_loc."Item No.") THEN
            BookItemHandler_loc.AddToAvailable(BookItemTemp_loc, BookItem_loc, Rec);
        UNTIL DocumentLine_loc.NEXT = 0;

      //bner Book Item Temp List
      PAGE.RUNMODAL(52016, BookItemTemp_loc);

      //Ops‘tter filtre
      BookItemTemp_loc.RESET;
      BookItemTemp_loc.SETRANGE(Select, TRUE);

      //Overf›rer data til Document Subform
      IF BookItemTemp_loc.FINDSET THEN
        REPEAT
          BookItemHandler_loc.AddToSubform(BookItemTemp_loc, BookItem_loc, Rec);
        UNTIL BookItemTemp_loc.NEXT = 0;

      // Update Matrix values
      UpdateMatrixQty(Rec);

      //Overf›rer data til reserved
      DocumentLine_loc.SETRANGE("Document Type", DocumentLine_loc."Document Type"::Rent);
      IF DocumentLine_loc.FINDSET THEN
        REPEAT
          AddToReserved;
        UNTIL DocumentLine_loc.NEXT = 0;
    END;

    PROCEDURE CheckOrderStatus@1160810002(VAR DocumentType_par@1160810002 : ',Rent,Reserve';DocumentNo_par@1160810001 : Code[20]);
    VAR
      DocumentHeader_loc@1160810000 : Record 52003;
    BEGIN
      //Error hvis man redigere en frigivet vare
      IF DocumentHeader_loc.GET(DocumentType_par, DocumentNo_par) THEN;
      //  MESSAGE('KEJ ERROR');

      IF DocumentHeader_loc.Status = DocumentHeader_loc.Status::Released THEN
        ERROR(Text0001);
    END;

    PROCEDURE ChangeOrderStatus@1160810005(DocumentType_par@1160810003 : ',Rent,Reserve';DocumentNo_par@1160810000 : Code[20];Status_par@1160810001 : 'Open,Released');
    VAR
      DocumentHeader_loc@1160810002 : Record 52003;
    BEGIN
      //’ndre status
      IF DocumentHeader_loc.GET(DocumentType_par, DocumentNo_par) THEN BEGIN
        DocumentHeader_loc.Status := Status_par;
        DocumentHeader_loc.MODIFY;
      END;
    END;

    PROCEDURE IsReserved@1160810009(BookItem_par@1160810001 : Record 52012;DocumentLine_par@1160810000 : Record 52004) : Boolean;
    VAR
      DocumentLine_loc@1160810002 : Record 52004;
      Reserved_loc@1160810003 : Record 52014;
      DocumentHeader_loc@1160810004 : Record 52003;
    BEGIN
      IF DocumentHeader_loc.GET(DocumentLine_par."Document Type", DocumentLine_par."Document No.") THEN;

      Reserved_loc.RESET;
      // Reserved_loc.SETRANGE(, DocumentLine_par."Document Type"::Reserve);
      Reserved_loc.SETRANGE("Item No.", BookItem_par."No.");
      Reserved_loc.SETFILTER("From Reserve Date", '%1..%2', DocumentHeader_loc."From Date", DocumentHeader_loc."To Date");
      Reserved_loc.SETFILTER("To Reserve Date", '%1..%2', DocumentHeader_loc."From Date", DocumentHeader_loc."To Date");
      IF Reserved_loc.ISEMPTY THEN
        EXIT(FALSE)
      ELSE
        EXIT(TRUE);
    END;

    LOCAL PROCEDURE AddToReserved@1160810003();
    VAR
      DocumentHeader_loc@1160810000 : Record 52003;
      Reserved_loc@1160810001 : Record 52014;
    BEGIN
      Reserved_loc.RESET;
      DocumentHeader_loc.RESET;
    END;

    PROCEDURE CalculateDiscount@1160810006(BookNo_par@1160810001 : Code[20];CustomerNo_par@1160810000 : Code[20]) : Decimal;
    VAR
      DiscountAmount_loc@1160810002 : Decimal;
      Book_loc@1160810003 : Record 52002;
      BookCustomer_loc@1160810004 : Record 52001;
      BookPrice_loc@1160810005 : Decimal;
    BEGIN
      CLEAR(DiscountAmount_loc);

      IF BookNo_par = '' THEN
        EXIT(0);

      IF Book_loc.GET(BookNo_par) THEN BEGIN
        BookPrice_loc := Book_loc."Price (LCY)";

        CalculateBookDiscount(BookNo_par, DiscountAmount_loc);

        IF CustomerNo_par <> '' THEN
          IF BookCustomer_loc.GET(CustomerNo_par) THEN
            CalculateCustomerDiscount(CustomerNo_par, DiscountAmount_loc);

      // Returnere rabat i kroner
        EXIT(BookPrice_loc - DiscountAmount_loc);
      END ELSE
        EXIT(0);
    END;

    LOCAL PROCEDURE CalculateBookDiscount@1160810014(BookNo_par@1160810000 : Code[20];VAR DiscountAmount_par@1160810001 : Decimal) : Decimal;
    VAR
      Discount_loc@1160810002 : Record 52007;
      Book_loc@1160810003 : Record 52002;
    BEGIN
      Discount_loc.RESET;
      Discount_loc.SETRANGE(Type, Discount_loc.Type::Book);
      Discount_loc.SETRANGE("No.", BookNo_par);
      Discount_loc.SETFILTER("Starting Date", '%1..', WORKDATE);
      Discount_loc.SETFILTER("Ending Date", '..%1', WORKDATE);
      IF Discount_loc.FINDFIRST THEN BEGIN
        Book_loc.GET(BookNo_par);
        Book_loc.CALCFIELDS("Price (LCY)");
        DiscountAmount_par := Book_loc."Price (LCY)" * Discount_loc."Discount Percent" / 100 ; //Bel›bet der skal tr‘kkes fra
        EXIT(DiscountAmount_par);
      END ELSE
        EXIT(0);
    END;

    LOCAL PROCEDURE CalculateCustomerDiscount@1160810015(CustomerNo_par@1160810000 : Code[20];VAR DiscountAmount_par@1160810001 : Decimal) : Decimal;
    VAR
      Discount_loc@1160810002 : Record 52007;
      CustDiscount_loc@1160810003 : Decimal;
      Book_loc@1160810004 : Record 52002;
      BookCustomer_loc@1160810005 : Record 52001;
    BEGIN
      Discount_loc.RESET;
      Discount_loc.SETRANGE(Type, Discount_loc.Type::Customer);
      Discount_loc.SETRANGE("No.", CustomerNo_par);
      Discount_loc.SETFILTER("Starting Date", '<=%1', WORKDATE);
      Discount_loc.SETFILTER("Ending Date", '>%1', WORKDATE);

      IF Discount_loc.FINDFIRST THEN BEGIN
        BookCustomer_loc.GET(CustomerNo_par);
        Book_loc.CALCFIELDS("Price (LCY)");
        DiscountAmount_par := Book_loc."Price (LCY)" * Discount_loc."Discount Percent" / 100 ; //Bel›bet der skal tr‘kkes fra
        EXIT(DiscountAmount_par);
      END ELSE
        EXIT(0);
    END;

    PROCEDURE ReservationDelete@1160810007(VAR Rec@1160810001 : Record 52004);
    VAR
      Reserved_loc@1160810000 : Record 52014;
    BEGIN
      Reserved_loc.RESET;
      Rec.CALCFIELDS("Book Customer");
      Reserved_loc.SETRANGE("Customer No.", Rec."Book Customer");
      Reserved_loc.SETRANGE("Item No.", Rec."Item No.");
      Reserved_loc.SETRANGE("From Reserve Date", Rec."From Date");
      Reserved_loc.SETRANGE("To Reserve Date", Rec."To Date");
      IF Reserved_loc.FINDFIRST THEN
        Reserved_loc.DELETE(TRUE);
    END;

    PROCEDURE ReservationDelete2@1160810010(VAR Rec@1160810000 : Record 52003);
    VAR
      Reserved_loc@1160810001 : Record 52014;
      DocumentLine_loc@1160810002 : Record 52004;
    BEGIN
      IF Rec."Document Type" = Rec."Document Type"::Rent THEN
        ERROR(Text0003);

      Rec.RENAME(Rec."Document Type"::Rent, Rec."No.");

      Reserved_loc.RESET;
      Reserved_loc.SETCURRENTKEY("Document No.");
      Reserved_loc.SETRANGE("Document No.", Rec."No.");
      IF Reserved_loc.FINDSET THEN
        REPEAT
          Reserved_loc.DELETE(TRUE);
        UNTIL Reserved_loc.NEXT = 0;
    END;

    LOCAL PROCEDURE UpdateMatrixQty@1160810012(VAR DocumentLine_par@1160810000 : Record 52004);
    VAR
      DocumentLine_loc@1160810001 : Record 52004;
      DocumentLine2_loc@1160810002 : Record 52004;
    BEGIN
      DocumentLine_loc.RESET;
      DocumentLine_loc.SETRANGE("Document No.", DocumentLine_par."Document No.");
      DocumentLine_loc.SETRANGE(Matrix, DocumentLine_par."Line No.");
      DocumentLine_par."Line Quantity" := DocumentLine_loc.COUNT;
    END;

    [EventSubscriber(Table,52004,OnAfterDeleteEvent)]
    PROCEDURE MatrixDelete@1160810011(VAR Rec@1160810000 : Record 52004);
    VAR
      DocumentLine_loc@1160810001 : Record 52004;
    BEGIN
      IF Rec.ISTEMPORARY THEN
        EXIT;

      IF Rec.Type <> Rec.Type::Matrix THEN
        EXIT;

      DocumentLine_loc.RESET;
      DocumentLine_loc.SETRANGE("Document No.", Rec."Document No.");
      DocumentLine_loc.SETRANGE(Matrix, Rec."Line No.");
      IF DocumentLine_loc.FINDSET THEN
        REPEAT
          DocumentLine_loc.DELETE(TRUE);
        UNTIL DocumentLine_loc.NEXT = 0;
    END;

    [EventSubscriber(Table,52004,OnAfterInsertEvent)]
    LOCAL PROCEDURE DocumentLine_OnAfterInsert@1160810016(VAR Rec@1160810000 : Record 52004);
    VAR
      DocumentLine_loc@1160810001 : Record 52004;
    BEGIN
      IF Rec.ISTEMPORARY THEN
        EXIT;

      IF Rec.Type <> Rec.Type::Item THEN
        EXIT;

      IF DocumentLine_loc.GET(Rec."Document No.", Rec.Matrix) THEN BEGIN
        DocumentLine_loc.CALCFIELDS("Price (LCY)");
        DocumentLine_loc."Line amount" += Rec."Line Quantity" * Rec."Price (LCY)";
        DocumentLine_loc.MODIFY(TRUE);
      END;
    END;

    [EventSubscriber(Table,52004,OnBeforeInsertEvent)]
    LOCAL PROCEDURE DocumentLine_OnBeforeInsert@1160810020(VAR Rec@1160810000 : Record 52004);
    VAR
      DocumentLine_loc@1160810001 : Record 52004;
    BEGIN
      //Beregner matrix
      IF Rec.ISTEMPORARY THEN
        EXIT;

      IF Rec.Type = Rec.Type::Item THEN
        EXIT;

      DocumentLine_loc.RESET;
      DocumentLine_loc.SETRANGE("Document No.", Rec."Document No.");
      DocumentLine_loc.SETRANGE(Matrix, Rec."Line No.");
      DocumentLine_loc.CALCSUMS("Line amount", "Line Quantity");

      Rec."Line amount"   := DocumentLine_loc."Line amount";
      Rec."Line Quantity" := DocumentLine_loc."Line Quantity";
    END;

    BEGIN
    END.
  }
}

