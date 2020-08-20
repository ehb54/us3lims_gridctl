delete from autoflowAnalysis;
delete from autoflowAnalysisHistory;
insert into autoflowAnalysis
    (tripleName, filename, aprofileGUID, invID, statusJson)
    values
    ( "triple1", "file1", "guid1", 1, '{"to_process":["2DSA","2DSA_FM","FITMEN","2DSA_IT", "2DSA_MC"]}'),
    ( "triple2", "file2", "guid2", 2, '{"to_process":[{"2DSA": { "option_2DSA_1" : 123 }}, "2DSA_FM" ]}' ),
    ( "triple3", "file3", "guid3", 3, '{"to_process":[{"GA":{ "option_GA_3" : 123 }}]}' )
    ;
