delete from AutoflowAnalysis;
delete from AutoflowAnalysisHistory;
insert into AutoflowAnalysis
    (TripleName, AprofileGUID, FinalStage, status_json)
    values
    ( "triple1", "guid1", "aprofile1", '{"to_process":["2DSA","2DSA_FM","FITMEN","2DSA_IT", "2DSA_MC"]}'),
    ( "triple2", "guid2", "aprofile2", '{"to_process":[{"2DSA": { "option_2DSA_1" : 123 }}, "2DSA_FM" ]}' ),
    ( "triple3", "guid3", "aprofile3", '{"to_process":[{"GA":{ "option_GA_3" : 123 }}]}' )
    ;
    

